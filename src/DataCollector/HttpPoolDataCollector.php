<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\DataCollector;

use Aubes\HttpPoolBundle\Exception\CallbackException;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

final class HttpPoolDataCollector extends AbstractDataCollector
{
    public function __construct(
        private readonly TraceablePoolFactory $factory,
    ) {
    }

    public static function getTemplate(): string
    {
        return '@HttpPool/data_collector/pool.html.twig';
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $poolsData = [];

        foreach ($this->factory->getPools() as $index => $pool) {
            $traces = $pool->getTraces();
            $results = $pool->getLastResults();

            $totalRequests = 0;
            $dedupHits = 0;
            $fanOutCount = 0;
            $fireCount = 0;

            foreach ($traces as $trace) {
                if ($trace->dedup) {
                    ++$dedupHits;
                    continue; // dedup ne genere pas de requete HTTP
                }

                if (PoolRequestType::Fire === $trace->type) {
                    ++$fireCount;
                }

                ++$totalRequests;

                if ($trace->fanOut) {
                    ++$fanOutCount;
                }
            }

            $httpErrors = 0;
            $callbackErrors = 0;
            $errors = $results?->getErrors() ?? [];

            foreach ($errors as $error) {
                if ($error instanceof CallbackException) {
                    ++$callbackErrors;
                } else {
                    ++$httpErrors;
                }
            }

            $poolsData[] = [
                'index' => $index,
                'traces' => array_map(static function (PoolRequestTrace $t) use ($results, $errors) {
                    $statusCode = null;
                    if (null !== $t->key && !$t->dedup) {
                        $response = $results?->has($t->key) ? $results->get($t->key) : null;
                        if (null !== $response) {
                            $statusCode = $response->getStatusCode();
                        } elseif (isset($errors[$t->key]) && $errors[$t->key] instanceof HttpExceptionInterface) {
                            $statusCode = $errors[$t->key]->getResponse()->getStatusCode();
                        }
                    }

                    return [
                        'key' => $t->key,
                        'method' => $t->method,
                        'url' => $t->url,
                        'type' => $t->type->value,
                        'fan_out' => $t->fanOut,
                        'dedup' => $t->dedup,
                        'initiator' => $t->initiator,
                        'status_code' => $statusCode,
                        'error' => null !== $t->key && isset($errors[$t->key]) ? $errors[$t->key]->getMessage() : null,
                    ];
                }, $traces),
                'flush_duration_ms' => round($pool->getFlushDuration() * 1000, 2),
                'total_requests' => $totalRequests,
                'dedup_hits' => $dedupHits,
                'fan_out_count' => $fanOutCount,
                'fire_count' => $fireCount,
                'http_errors' => $httpErrors,
                'callback_errors' => $callbackErrors,
                'has_errors' => $results?->hasErrors() ?? false,
            ];
        }

        $this->data = [
            'pools' => $poolsData,
            'pool_count' => \count($poolsData),
            'total_requests' => array_sum(array_column($poolsData, 'total_requests')),
            'total_duration_ms' => round(array_sum(array_column($poolsData, 'flush_duration_ms')), 2),
        ];
    }

    public function getName(): string
    {
        return 'http_pool';
    }

    public function getPoolCount(): int
    {
        return (int) ($this->data['pool_count'] ?? 0); // @phpstan-ignore cast.int
    }

    public function getTotalRequests(): int
    {
        return (int) ($this->data['total_requests'] ?? 0); // @phpstan-ignore cast.int
    }

    public function getTotalDurationMs(): float
    {
        return (float) ($this->data['total_duration_ms'] ?? 0.0); // @phpstan-ignore cast.double
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPools(): array
    {
        return \is_array($this->data['pools'] ?? null) ? $this->data['pools'] : []; // @phpstan-ignore return.type
    }
}
