<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\DataCollector;

use Aubes\HttpPoolBundle\Exception\PoolException;
use Aubes\HttpPoolBundle\Pool\Pool;
use Aubes\HttpPoolBundle\Pool\PoolEntry;
use Aubes\HttpPoolBundle\Pool\PoolInterface;
use Aubes\HttpPoolBundle\Pool\PoolResults;

final class TraceablePool implements PoolInterface
{
    /** @var list<PoolRequestTrace> */
    private array $traces = [];

    private bool $flushing = false;
    private float $flushDuration = 0.0;
    private ?PoolResults $lastResults = null;

    public function __construct(
        private readonly PoolInterface $inner,
    ) {
        if ($this->inner instanceof Pool) {
            $this->inner->setCallbackPool($this);
        }
    }

    public function add(string $key, string $method, string $url, array $options = []): PoolEntry
    {
        $this->traces[] = new PoolRequestTrace($key, $method, $url, PoolRequestType::Add, $this->flushing, initiator: $this->getInitiator());

        return $this->inner->add($key, $method, $url, $options);
    }

    public function addOnce(string $key, string $method, string $url, array $options = []): PoolEntry
    {
        $dedup = $this->inner->has($key);
        $this->traces[] = new PoolRequestTrace($key, $method, $url, PoolRequestType::AddOnce, $this->flushing, $dedup, $this->getInitiator());

        return $this->inner->addOnce($key, $method, $url, $options);
    }

    public function fire(string $method, string $url, array $options = []): void
    {
        $this->traces[] = new PoolRequestTrace(null, $method, $url, PoolRequestType::Fire, $this->flushing, initiator: $this->getInitiator());
        $this->inner->fire($method, $url, $options);
    }

    public function has(string $key): bool
    {
        return $this->inner->has($key);
    }

    public function flush(): PoolResults
    {
        $this->flushing = true;
        $start = microtime(true);

        try {
            $this->lastResults = $this->inner->flush();

            return $this->lastResults;
        } catch (PoolException $e) {
            $this->lastResults = new PoolResults([], $e->getErrors());
            throw $e;
        } catch (\Throwable $e) {
            // StopOnFirst or other: single error keyed by exception message
            $this->lastResults = new PoolResults([], ['_flush_error' => $e]);
            throw $e;
        } finally {
            $this->flushDuration = microtime(true) - $start;
            $this->flushing = false;
        }
    }

    /**
     * @return list<PoolRequestTrace>
     */
    public function getTraces(): array
    {
        return $this->traces;
    }

    public function getFlushDuration(): float
    {
        return $this->flushDuration;
    }

    public function getLastResults(): ?PoolResults
    {
        return $this->lastResults;
    }

    private function getInitiator(): ?string
    {
        return $this->inner instanceof Pool ? $this->inner->getCurrentExecutingKey() : null;
    }
}
