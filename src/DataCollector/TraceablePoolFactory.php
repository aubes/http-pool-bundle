<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\DataCollector;

use Aubes\HttpPoolBundle\ErrorStrategy;
use Aubes\HttpPoolBundle\Pool\PoolFactoryInterface;
use Aubes\HttpPoolBundle\Pool\PoolInterface;
use Symfony\Contracts\Service\ResetInterface;

final class TraceablePoolFactory implements PoolFactoryInterface, ResetInterface
{
    /** @var list<TraceablePool> */
    private array $pools = [];

    public function __construct(
        private readonly PoolFactoryInterface $inner,
    ) {
    }

    public function create(
        ?int $concurrency = null,
        ?ErrorStrategy $errorStrategy = null,
        array $rateLimits = [],
        ?array $retry = null,
    ): PoolInterface {
        $pool = new TraceablePool($this->inner->create($concurrency, $errorStrategy, $rateLimits, $retry));
        $this->pools[] = $pool;

        return $pool;
    }

    /**
     * @return list<TraceablePool>
     */
    public function getPools(): array
    {
        return $this->pools;
    }

    public function reset(): void
    {
        $this->pools = [];
    }
}
