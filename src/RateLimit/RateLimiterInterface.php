<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\RateLimit;

interface RateLimiterInterface
{
    /**
     * Configures the rate limit for a host.
     *
     * Resets the token bucket to full capacity. Should be called once
     * at construction time, not during pool execution.
     */
    public function configure(string $host, int $maxPerSecond): void;

    /**
     * Checks if a request can be sent to the given host.
     *
     * If a token is available, consumes it and returns 0.0.
     * If not, returns the delay in seconds to wait before retrying.
     * No token is consumed when a delay is returned.
     *
     * @return float 0.0 if available, otherwise delay in seconds
     */
    public function getDelay(string $host): float;
}
