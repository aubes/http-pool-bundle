<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\Pool;

interface PoolInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function add(string $key, string $method, string $url, array $options = []): PoolEntry;

    /**
     * Fire-and-forget: response ignored, errors logged.
     *
     * @param array<string, mixed> $options
     */
    public function fire(string $method, string $url, array $options = []): void;

    /**
     * Add with deduplication: unknown key schedules, existing key returns current entry,
     * completed key executes then() immediately.
     *
     * @param array<string, mixed> $options
     */
    public function addOnce(string $key, string $method, string $url, array $options = []): PoolEntry;

    public function has(string $key): bool;

    public function flush(): PoolResults;
}
