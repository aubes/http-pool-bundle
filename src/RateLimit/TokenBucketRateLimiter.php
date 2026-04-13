<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\RateLimit;

final class TokenBucketRateLimiter implements RateLimiterInterface
{
    /** @var array<string, int> */
    private array $limits = [];

    /** @var array<string, float> */
    private array $tokens = [];

    /** @var array<string, float> */
    private array $lastRefill = [];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /**
     * @param (\Closure(): float)|null $clock Custom clock (for testing)
     */
    public function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn () => microtime(true);
    }

    public function configure(string $host, int $maxPerSecond): void
    {
        $this->limits[$host] = $maxPerSecond;
        $this->tokens[$host] = (float) $maxPerSecond;
        $this->lastRefill[$host] = ($this->clock)();
    }

    public function getDelay(string $host): float
    {
        if (!isset($this->limits[$host])) {
            return 0.0;
        }

        $this->refill($host);

        if ($this->tokens[$host] >= 1.0) {
            $this->tokens[$host] -= 1.0;

            return 0.0;
        }

        // Not enough tokens, compute delay
        $deficit = 1.0 - $this->tokens[$host];
        $rate = $this->limits[$host]; // tokens/seconde

        return $deficit / $rate;
    }

    private function refill(string $host): void
    {
        $now = ($this->clock)();
        $elapsed = $now - $this->lastRefill[$host];

        if ($elapsed <= 0.0) {
            return;
        }

        $rate = $this->limits[$host];
        $this->tokens[$host] = min(
            (float) $rate, // cap au max
            $this->tokens[$host] + $elapsed * $rate,
        );
        $this->lastRefill[$host] = $now;
    }
}
