<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\Pool;

use Aubes\HttpPoolBundle\ErrorStrategy;
use Aubes\HttpPoolBundle\Exception\CallbackException;
use Aubes\HttpPoolBundle\Exception\CircularReferenceException;
use Aubes\HttpPoolBundle\Exception\PoolException;
use Aubes\HttpPoolBundle\RateLimit\RateLimiterInterface;
use Aubes\HttpPoolBundle\Retry\RetryStrategyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Not thread-safe: disposable, request-scoped.
 */
final class Pool implements PoolInterface
{
    /** @var PoolEntry[] */
    private array $queue = [];

    /** @var array<string, true> */
    private array $registeredKeys = [];

    /** @var array<string, true> */
    private array $fireAndForgetKeys = [];

    private int $fireAndForgetCounter = 0;

    /** @var PoolEntry[] */
    private array $delayed = [];

    /** @var array<string, int> */
    private array $attempts = [];

    /** @var array<string, ResponseInterface> Cache for addOnce */
    private array $completedResponses = [];

    /** @var array<string, \Throwable> Cache for addOnce */
    private array $completedErrors = [];

    /** @var array<string, PoolEntry> */
    private array $entryByKey = [];

    /** @var list<array{entry: PoolEntry, response: ResponseInterface}> */
    private array $resolvedEntries = [];

    /** @var list<array{entry: PoolEntry, exception: \Throwable}> */
    private array $rejectedEntries = [];

    /** @var array<string, true> For cycle detection */
    private array $drainedKeys = [];

    /** @var PoolInterface Passed to then()/catch() callbacks, replaceable by a decorator. */
    private PoolInterface $callbackPool;

    private ?string $currentExecutingKey = null;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock Custom clock (for testing)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $concurrency = 10,
        private readonly ErrorStrategy $errorStrategy = ErrorStrategy::Collect,
        private readonly ?RateLimiterInterface $rateLimiter = null,
        private readonly ?RetryStrategyInterface $retryStrategy = null,
        private readonly ?LoggerInterface $logger = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn () => microtime(true);
        $this->callbackPool = $this;
    }

    /**
     * @internal Used by TraceablePool to intercept calls from callbacks
     */
    public function setCallbackPool(PoolInterface $pool): void
    {
        $this->callbackPool = $pool;
    }

    /**
     * @internal Used by TraceablePool to get the initiator of a fan-out
     */
    public function getCurrentExecutingKey(): ?string
    {
        return $this->currentExecutingKey;
    }

    public function add(string $key, string $method, string $url, array $options = []): PoolEntry
    {
        if (isset($this->registeredKeys[$key])) {
            throw new \InvalidArgumentException(\sprintf('Key "%s" is already registered in this pool.', $key));
        }

        $entry = new PoolEntry($key, $method, $url, $options);
        $this->queue[] = $entry;
        $this->registeredKeys[$key] = true;
        $this->entryByKey[$key] = $entry;

        return $entry;
    }

    public function addOnce(string $key, string $method, string $url, array $options = []): PoolEntry
    {
        // key already completed successfully -> return a "resolved" entry
        if (isset($this->completedResponses[$key])) {
            $this->detectCycle($key);
            $entry = new PoolEntry($key, $method, $url, $options);
            $this->resolvedEntries[] = ['entry' => $entry, 'response' => $this->completedResponses[$key]];

            return $entry;
        }

        // key already completed with error -> return a "rejected" entry
        if (isset($this->completedErrors[$key])) {
            $this->detectCycle($key);
            $entry = new PoolEntry($key, $method, $url, $options);
            $this->rejectedEntries[] = ['entry' => $entry, 'exception' => $this->completedErrors[$key]];

            return $entry;
        }

        // key in-flight or pending -> return the existing entry
        if (isset($this->entryByKey[$key])) {
            return $this->entryByKey[$key];
        }

        // unknown key -> schedule like add()
        $entry = new PoolEntry($key, $method, $url, $options);
        $this->queue[] = $entry;
        $this->registeredKeys[$key] = true;
        $this->entryByKey[$key] = $entry;

        return $entry;
    }

    public function has(string $key): bool
    {
        return isset($this->registeredKeys[$key]);
    }

    public function fire(string $method, string $url, array $options = []): void
    {
        $key = '__fire_'.$this->fireAndForgetCounter++;
        $entry = new PoolEntry($key, $method, $url, $options);
        $this->queue[] = $entry;
        $this->fireAndForgetKeys[$key] = true;
    }

    public function flush(): PoolResults
    {
        $this->drainedKeys = [];

        /** @var array<string, ResponseInterface> $responses */
        $responses = [];
        /** @var array<string, \Throwable> $errors */
        $errors = [];
        /** @var array<string, ResponseInterface> $inFlight */
        $inFlight = [];
        /** @var array<string, PoolEntry> $entries */
        $entries = [];

        while (!empty($this->queue) || !empty($inFlight) || !empty($this->delayed) || !empty($this->resolvedEntries) || !empty($this->rejectedEntries)) {
            $this->drainResolvedEntries($errors, $inFlight);
            $this->drainRejectedEntries($errors, $inFlight);
            $this->promoteDelayedEntries();

            while (\count($inFlight) < $this->concurrency && !empty($this->queue)) {
                $entry = array_shift($this->queue);
                $host = $this->extractHost($entry->url);
                $delay = $this->rateLimiter?->getDelay($host) ?? 0.0;

                if ($delay > 0.0) {
                    $entry->delayUntil = ($this->clock)() + $delay;
                    $this->delayed[] = $entry;
                    continue;
                }

                $options = $entry->options;
                $options['user_data'] = ['pool_key' => $entry->key];

                $response = $this->httpClient->request($entry->method, $entry->url, $options);

                $inFlight[$entry->key] = $response;
                $entries[$entry->key] = $entry;
            }

            if (empty($inFlight)) {
                if (!empty($this->delayed)) {
                    // Wait for the shortest delay
                    $minWait = min(array_map(
                        fn (PoolEntry $e) => $e->delayUntil - ($this->clock)(),
                        $this->delayed,
                    ));
                    usleep((int) max(0, $minWait * 1_000_000));
                    continue;
                }
                break;
            }

            try {
                foreach ($this->httpClient->stream($inFlight) as $response => $chunk) {
                    if (!$chunk->isLast()) {
                        continue;
                    }

                    $key = $this->findKeyForResponse($response, $inFlight);
                    $entry = $entries[$key];
                    unset($inFlight[$key], $entries[$key]);

                    $isFireAndForget = isset($this->fireAndForgetKeys[$key]);

                    if (!$isFireAndForget) {
                        $responses[$key] = $response;
                        $this->completedResponses[$key] = $response;
                    }

                    $callbackError = $this->executeCallbacks($entry, $response);

                    if (null !== $callbackError) {
                        $this->handleError($key, new CallbackException($response, $callbackError), $entry, $isFireAndForget, $errors);
                        $this->stopOnFirstIfNeeded($key, $errors, $inFlight);
                    }

                    // Fan-out: break to dispatch new requests
                    if (!empty($this->queue) && \count($inFlight) < $this->concurrency) {
                        break;
                    }
                }
            } catch (HttpExceptionInterface $e) {
                $failedResponse = $e->getResponse();
                $key = $this->findKeyForResponse($failedResponse, $inFlight);
                $entry = $entries[$key];
                unset($inFlight[$key], $entries[$key]);

                if ($this->scheduleRetry($key, $entry, $failedResponse)) {
                    continue;
                }

                $isFireAndForget = isset($this->fireAndForgetKeys[$key]);
                $this->handleError($key, $e, $entry, $isFireAndForget, $errors);
                $this->stopOnFirstIfNeeded($key, $errors, $inFlight);
            }
        }

        if (ErrorStrategy::ThrowAll === $this->errorStrategy && !empty($errors)) {
            throw new PoolException($errors);
        }

        return new PoolResults($responses, $errors);
    }

    /**
     * @param array<string, \Throwable> $errors
     */
    private function handleError(
        string $key,
        \Throwable $exception,
        PoolEntry $entry,
        bool $isFireAndForget,
        array &$errors,
    ): void {
        $callbacks = $entry->getOnRejected();

        if (!empty($callbacks)) {
            $lastException = null;
            $this->currentExecutingKey = $key;

            foreach ($callbacks as $callback) {
                try {
                    $callback($exception, $this->callbackPool);
                } catch (\Throwable $rejectException) {
                    $lastException = $rejectException;
                }
            }

            $this->currentExecutingKey = null;

            if (null === $lastException) {
                return;
            }

            $exception = $lastException;
        }

        if ($isFireAndForget) {
            $this->logger?->error('Fire-and-forget request failed: {url}', [
                'url' => $entry->url,
                'exception' => $exception,
            ]);

            return;
        }

        // Only cache HTTP errors (successful requests already in completedResponses)
        if (!isset($this->completedResponses[$key])) {
            $this->completedErrors[$key] = $exception;
        }

        $errors[$key] = $exception;
    }

    /**
     * @param array<string, ResponseInterface> $inFlight
     */
    private function cancelAll(array &$inFlight): void
    {
        foreach ($inFlight as $response) {
            $response->cancel();
        }

        $inFlight = [];
        $this->queue = [];
        $this->delayed = [];
        $this->resolvedEntries = [];
        $this->rejectedEntries = [];
    }

    /**
     * @param array<string, \Throwable>        $errors
     * @param array<string, ResponseInterface> $inFlight
     */
    private function stopOnFirstIfNeeded(string $key, array &$errors, array &$inFlight): void
    {
        if (ErrorStrategy::StopOnFirst === $this->errorStrategy && isset($errors[$key])) {
            $this->cancelAll($inFlight);

            throw $errors[$key];
        }
    }

    private function scheduleRetry(string $key, PoolEntry $entry, ResponseInterface $response): bool
    {
        if (null === $this->retryStrategy) {
            return false;
        }

        $statusCode = $response->getStatusCode();
        $attempt = $this->attempts[$key] ?? 0;

        if (!$this->retryStrategy->shouldRetry($statusCode, $attempt)) {
            return false;
        }

        $this->attempts[$key] = $attempt + 1;

        $headers = $response->getHeaders(false);
        $flatHeaders = array_map(static fn (array $values) => $values[0] ?? '', $headers);

        $delayMs = $this->retryStrategy->getDelay($statusCode, $attempt, $flatHeaders);

        if ($delayMs > 0) {
            $entry->delayUntil = ($this->clock)() + ($delayMs / 1000.0);
            $this->delayed[] = $entry;
        } else {
            $this->queue[] = $entry;
        }

        return true;
    }

    /**
     * Drain resolved entries queued by addOnce() on a completed key.
     *
     * @param array<string, \Throwable>        $errors
     * @param array<string, ResponseInterface> $inFlight
     */
    private function drainResolvedEntries(array &$errors, array &$inFlight): void
    {
        while (!empty($this->resolvedEntries)) {
            $resolved = $this->resolvedEntries;
            $this->resolvedEntries = [];

            foreach ($resolved as ['entry' => $entry, 'response' => $response]) {
                $this->drainedKeys[$entry->key] = true;
                $callbackError = $this->executeCallbacks($entry, $response);

                if (null !== $callbackError) {
                    $isFireAndForget = isset($this->fireAndForgetKeys[$entry->key]);
                    $this->handleError($entry->key, new CallbackException($response, $callbackError), $entry, $isFireAndForget, $errors);
                    $this->stopOnFirstIfNeeded($entry->key, $errors, $inFlight);
                }
            }
        }
    }

    /**
     * Drain rejected entries queued by addOnce() on a failed key.
     *
     * @param array<string, \Throwable>        $errors
     * @param array<string, ResponseInterface> $inFlight
     */
    private function drainRejectedEntries(array &$errors, array &$inFlight): void
    {
        while (!empty($this->rejectedEntries)) {
            $rejected = $this->rejectedEntries;
            $this->rejectedEntries = [];

            foreach ($rejected as ['entry' => $entry, 'exception' => $exception]) {
                $this->drainedKeys[$entry->key] = true;
                $isFireAndForget = isset($this->fireAndForgetKeys[$entry->key]);
                $this->handleError($entry->key, $exception, $entry, $isFireAndForget, $errors);
                $this->stopOnFirstIfNeeded($entry->key, $errors, $inFlight);
            }
        }
    }

    private function promoteDelayedEntries(): void
    {
        $now = ($this->clock)();
        $stillDelayed = [];

        foreach ($this->delayed as $entry) {
            if ($entry->delayUntil <= $now) {
                array_unshift($this->queue, $entry);
            } else {
                $stillDelayed[] = $entry;
            }
        }

        $this->delayed = $stillDelayed;
    }

    /**
     * Detect circular addOnce() references (same key drained twice in one flush).
     */
    private function detectCycle(string $key): void
    {
        if (isset($this->drainedKeys[$key])) {
            throw new CircularReferenceException($key);
        }
    }

    /**
     * Run all then() callbacks; a throwing callback does not stop the others.
     *
     * @return \Throwable|null Last thrown exception, or null
     */
    private function executeCallbacks(PoolEntry $entry, ResponseInterface $response): ?\Throwable
    {
        $lastError = null;
        $this->currentExecutingKey = $entry->key;

        try {
            foreach ($entry->getOnFulfilled() as $callback) {
                try {
                    $callback($response, $this->callbackPool);
                } catch (CircularReferenceException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    if (null !== $lastError) {
                        $this->logger?->warning('then() callback exception superseded for key "{key}": {message}', [
                            'key' => $entry->key,
                            'message' => $lastError->getMessage(),
                            'exception' => $lastError,
                        ]);
                    }
                    $lastError = $e;
                }
            }
        } finally {
            $this->currentExecutingKey = null;
        }

        return $lastError;
    }

    private function extractHost(string $url): string
    {
        return (string) parse_url($url, \PHP_URL_HOST);
    }

    /**
     * @param array<string, ResponseInterface> $inFlight
     */
    private function findKeyForResponse(ResponseInterface $response, array $inFlight): string
    {
        // Fast path via user_data tag (works through TraceableResponse wrapping)
        /** @var array{pool_key: string}|null $userData */
        $userData = $response->getInfo('user_data');
        if (null !== $userData && isset($inFlight[$userData['pool_key']])) {
            return $userData['pool_key'];
        }

        // Fallback: identity match
        foreach ($inFlight as $key => $candidate) {
            if ($candidate === $response) {
                return $key;
            }
        }

        throw new \LogicException('Response not found in in-flight map.');
    }
}
