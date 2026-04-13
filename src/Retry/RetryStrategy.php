<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\Retry;

final class RetryStrategy implements RetryStrategyInterface
{
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    private const DEFAULT_MAX_DELAY = 30_000; // 30 seconds in ms

    /**
     * @param array<int, array{max: int, delay: int, multiplier: float}> $rules
     * @param int                                                        $maxDelay Maximum delay in milliseconds. Any computed or Retry-After delay above this value will be capped. 0 = no cap.
     * @param (\Closure(): int)|null                                     $clock    Custom clock returning Unix timestamp (for testing)
     */
    public function __construct(
        private readonly array $rules = [],
        private readonly bool $respectRetryAfter = false,
        private readonly int $maxDelay = self::DEFAULT_MAX_DELAY,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn () => time();
    }

    public function shouldRetry(int $statusCode, int $attempt): bool
    {
        if (!isset($this->rules[$statusCode])) {
            return false;
        }

        return $attempt < $this->rules[$statusCode]['max'];
    }

    /**
     * @param array<string, string> $responseHeaders
     */
    public function getDelay(int $statusCode, int $attempt, array $responseHeaders = []): int
    {
        // Retry-After (429)
        if (429 === $statusCode && $this->respectRetryAfter) {
            $retryAfter = $this->parseRetryAfter($responseHeaders);
            if ($retryAfter > 0) {
                return $this->capDelay($retryAfter);
            }
        }

        if (!isset($this->rules[$statusCode])) {
            return 0;
        }

        $rule = $this->rules[$statusCode];
        $delay = $rule['delay'];
        $multiplier = $rule['multiplier'];

        // Exponential backoff
        return $this->capDelay((int) ($delay * ($multiplier ** $attempt)));
    }

    private function capDelay(int $delay): int
    {
        if ($this->maxDelay > 0 && $delay > $this->maxDelay) {
            return $this->maxDelay;
        }

        return $delay;
    }

    /**
     * @param array<string, string> $headers
     */
    private function parseRetryAfter(array $headers): int
    {
        $value = $headers['retry-after'] ?? null;

        if (null === $value) {
            return 0;
        }

        if (is_numeric($value)) {
            return (int) ((float) $value * 1000);
        }

        // HTTP date (RFC 7231)
        $timestamp = strtotime($value);
        if (false === $timestamp) {
            return 0;
        }

        $delay = $timestamp - ($this->clock)();

        return max(0, $delay * 1000);
    }
}
