<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\Pool;

final class PoolEntry
{
    /** @var list<\Closure(\Symfony\Contracts\HttpClient\ResponseInterface, PoolInterface): void> */
    private array $onFulfilled = [];

    /** @var list<\Closure(\Throwable, PoolInterface): void> */
    private array $onRejected = [];

    /** @internal Used by Pool for rate limiting */
    public float $delayUntil = 0.0;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $key,
        public readonly string $method,
        public readonly string $url,
        public readonly array $options = [],
    ) {
    }

    /**
     * @param \Closure(\Symfony\Contracts\HttpClient\ResponseInterface, PoolInterface): void $callback
     */
    public function then(\Closure $callback): self
    {
        $this->onFulfilled[] = $callback;

        return $this;
    }

    /**
     * If no catch re-throws, the error is considered handled.
     *
     * @param \Closure(\Throwable, PoolInterface): void $callback
     */
    public function catch(\Closure $callback): self
    {
        $this->onRejected[] = $callback;

        return $this;
    }

    /**
     * @return list<\Closure(\Symfony\Contracts\HttpClient\ResponseInterface, PoolInterface): void>
     */
    public function getOnFulfilled(): array
    {
        return $this->onFulfilled;
    }

    /**
     * @return list<\Closure(\Throwable, PoolInterface): void>
     */
    public function getOnRejected(): array
    {
        return $this->onRejected;
    }
}
