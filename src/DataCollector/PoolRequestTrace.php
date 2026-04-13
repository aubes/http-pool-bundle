<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\DataCollector;

final class PoolRequestTrace
{
    public function __construct(
        public readonly ?string $key,
        public readonly string $method,
        public readonly string $url,
        public readonly PoolRequestType $type,
        public readonly bool $fanOut,
        public readonly bool $dedup = false,
        public readonly ?string $initiator = null,
    ) {
    }
}
