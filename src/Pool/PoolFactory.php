<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\Pool;

use Aubes\HttpPoolBundle\ErrorStrategy;
use Aubes\HttpPoolBundle\RateLimit\TokenBucketRateLimiter;
use Aubes\HttpPoolBundle\Retry\RetryStrategy;
use Aubes\HttpPoolBundle\Retry\RetryStrategyInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PoolFactory implements PoolFactoryInterface
{
    /**
     * @param array<string, int> $defaultRateLimits host => req/s
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $defaultConcurrency = 10,
        private readonly ErrorStrategy $defaultErrorStrategy = ErrorStrategy::Collect,
        private readonly ?RetryStrategyInterface $defaultRetryStrategy = null,
        private readonly array $defaultRateLimits = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function create(
        ?int $concurrency = null,
        ?ErrorStrategy $errorStrategy = null,
        array $rateLimits = [],
        ?array $retry = null,
    ): PoolInterface {
        $finalConcurrency = $concurrency ?? $this->defaultConcurrency;
        $finalErrorStrategy = $errorStrategy ?? $this->defaultErrorStrategy;

        // Rate limiter: fresh instance per pool (request-scoped)
        $mergedRateLimits = array_replace($this->defaultRateLimits, $rateLimits);
        $rateLimiter = null;

        // Shared clock for consistent time across pool and rate limiter
        $clock = static fn () => microtime(true);

        if (!empty($mergedRateLimits)) {
            $rateLimiter = new TokenBucketRateLimiter($clock);
            foreach ($mergedRateLimits as $host => $maxPerSecond) {
                $rateLimiter->configure($host, $maxPerSecond);
            }
        }

        // Retry: null = use bundle default, [] = no retry, non-empty = build from params
        if (null === $retry) {
            $retryStrategy = $this->defaultRetryStrategy;
        } elseif ([] === $retry) {
            $retryStrategy = null;
        } else {
            $rules = [];
            $respectRetryAfter = false;

            foreach ($retry as $status => $config) {
                if ('respect_retry_after' === $config) {
                    $respectRetryAfter = true;
                    continue;
                }

                $rules[$status] = [
                    'max' => (int) $config,
                    'delay' => 500,
                    'multiplier' => 2.0,
                ];
            }

            $retryStrategy = new RetryStrategy($rules, $respectRetryAfter);
        }

        return new Pool(
            $this->httpClient,
            $finalConcurrency,
            $finalErrorStrategy,
            $rateLimiter,
            $retryStrategy,
            $this->logger,
            $clock,
        );
    }
}
