<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle\Tests\Pool;

use Aubes\HttpPoolBundle\Pool\Pool;
use Aubes\HttpPoolBundle\Pool\PoolFactory;
use Aubes\HttpPoolBundle\Retry\RetryStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PoolFactoryTest extends TestCase
{
    public function testCreateReturnsPoolInstance(): void
    {
        $factory = new PoolFactory(new MockHttpClient());

        $pool = $factory->create();

        self::assertInstanceOf(Pool::class, $pool);
    }

    public function testCreatedPoolWorks(): void
    {
        $client = new MockHttpClient([
            new MockResponse('hello'),
        ]);

        $factory = new PoolFactory($client);
        $pool = $factory->create(concurrency: 5);

        $pool->add('test', 'GET', 'https://api.test/hello');
        $results = $pool->flush();

        self::assertTrue($results->has('test'));
        self::assertSame('hello', $results->get('test')->getContent());
    }

    public function testEachCreateReturnsNewPool(): void
    {
        $factory = new PoolFactory(new MockHttpClient());

        $pool1 = $factory->create();
        $pool2 = $factory->create();

        self::assertNotSame($pool1, $pool2);
    }

    public function testRateLimitsFromConfig(): void
    {
        $requestCount = 0;
        $client = new MockHttpClient(static function () use (&$requestCount) {
            ++$requestCount;

            return new MockResponse('ok');
        });

        $factory = new PoolFactory(
            $client,
            defaultRateLimits: ['api.test' => 100],
        );

        $pool = $factory->create();
        $pool->add('a', 'GET', 'https://api.test/a');
        $pool->add('b', 'GET', 'https://api.test/b');
        $results = $pool->flush();

        self::assertCount(2, $results->all());
    }

    public function testRateLimitsOverrideAtCreate(): void
    {
        $client = new MockHttpClient([
            new MockResponse('ok'),
        ]);

        $factory = new PoolFactory($client, defaultRateLimits: ['old.host' => 10]);

        // Override avec un nouveau host
        $pool = $factory->create(rateLimits: ['new.host' => 20]);
        $pool->add('test', 'GET', 'https://new.host/test');
        $results = $pool->flush();

        self::assertTrue($results->has('test'));
    }

    public function testRetryFromCreateParams(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('ok after retry'),
        ]);

        $factory = new PoolFactory($client);
        $pool = $factory->create(retry: [503 => 3]);

        $pool->add('test', 'GET', 'https://api.test/test');
        $results = $pool->flush();

        self::assertTrue($results->has('test'));
        self::assertSame('ok after retry', $results->get('test')->getContent());
    }

    public function testRetryEmptyArrayDisablesRetry(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
        ]);

        $retryStrategy = new RetryStrategy([503 => ['max' => 3, 'delay' => 100, 'multiplier' => 2.0]]);
        $factory = new PoolFactory($client, defaultRetryStrategy: $retryStrategy);

        // Explicitly disable retry
        $pool = $factory->create(retry: []);

        $pool->add('test', 'GET', 'https://api.test/test');
        $results = $pool->flush();

        // No retry: error collected
        self::assertTrue($results->hasErrors());
    }

    public function testRetryNullUsesDefault(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse('ok after retry'),
        ]);

        $retryStrategy = new RetryStrategy([503 => ['max' => 3, 'delay' => 0, 'multiplier' => 1.0]]);
        $factory = new PoolFactory($client, defaultRetryStrategy: $retryStrategy);

        // null = use bundle default
        $pool = $factory->create();

        $pool->add('test', 'GET', 'https://api.test/test');
        $results = $pool->flush();

        self::assertTrue($results->has('test'));
        self::assertFalse($results->hasErrors());
    }

    public function testNullableConcurrencyUsesDefault(): void
    {
        $client = new MockHttpClient([
            new MockResponse('ok'),
        ]);

        $factory = new PoolFactory($client, defaultConcurrency: 3);

        // Explicit concurrency: null = use default (3), not the interface default
        $pool = $factory->create();
        $pool->add('test', 'GET', 'https://api.test/test');
        $results = $pool->flush();

        self::assertTrue($results->has('test'));
    }
}
