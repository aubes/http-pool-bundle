<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\Pool;

use Aubes\HttpPoolBundle\ErrorStrategy;

interface PoolFactoryInterface
{
    /**
     * @param array<string, int>     $rateLimits host => req/s
     * @param array<int, int|string> $retry      status => max_attempts | 'respect_retry_after'
     */
    public function create(
        ?int $concurrency = null,
        ?ErrorStrategy $errorStrategy = null,
        array $rateLimits = [],
        ?array $retry = null,
    ): PoolInterface;
}
