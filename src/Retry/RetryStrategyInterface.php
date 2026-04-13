<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\Retry;

interface RetryStrategyInterface
{
    public function shouldRetry(int $statusCode, int $attempt): bool;

    /**
     * @return int Delay in milliseconds
     */
    /**
     * @param array<string, string> $responseHeaders
     */
    public function getDelay(int $statusCode, int $attempt, array $responseHeaders = []): int;
}
