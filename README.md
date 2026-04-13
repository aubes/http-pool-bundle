# aubes/http-pool-bundle

[![CI](https://github.com/aubes/http-pool-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/aubes/http-pool-bundle/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/aubes/http-pool-bundle.svg)](https://packagist.org/packages/aubes/http-pool-bundle)
[![PHP Version](https://img.shields.io/badge/php-8.3%2B-blue.svg)](https://www.php.net)
[![Symfony Version](https://img.shields.io/badge/symfony-6.4%20%7C%207.x%20%7C%208.x-green.svg)](https://symfony.com)

Concurrent HTTP orchestration for Symfony. Fan-out, rate limit, retry, chain: one fluent API, zero `stream()` boilerplate.

- **Bounded concurrency**: run up to N requests in parallel, the rest waits in a queue
- **Reactive chaining** (`then`/`catch`): a response can trigger new requests within the same `flush()`
- **Per-host rate limiting**: token bucket throttling per domain (req/s)
- **Retry with backoff**: configurable per status code, with `Retry-After` support
- **Fire-and-forget**: send requests without waiting, errors are logged silently
- **Deduplication** (`addOnce`): multiple consumers, one HTTP call
- **Named results**: access responses by key via `$results->get('user')`
- **3 error strategies**: collect, stop on first, or throw all

Built on top of `HttpClientInterface::stream()`, the only non-blocking async primitive in PHP.

## Installation

```bash
composer require aubes/http-pool-bundle
```

### Requirements

- PHP >= 8.3
- Symfony 6.4, 7.4 or 8.0

## Why?

Without this bundle, fetching a user and their orders concurrently looks like this:

```php
$responses = [];
$responses['user'] = $httpClient->request('GET', "https://api.example.com/users/{$userId}");
$responses['orders'] = $httpClient->request('GET', "https://api.example.com/orders?user={$userId}");

$results = [];
foreach ($httpClient->stream($responses) as $response => $chunk) {
    if ($chunk->isLast()) {
        $key = array_search($response, $responses, true);
        $results[$key] = $response->toArray();
    }
}
// No concurrency limit, no rate limiting, no retry, no fan-out,
// no error handling per request, and it gets worse with each new API.
```

With http-pool-bundle:

```php
$pool = $this->httpPool->create(concurrency: 10);

$pool->add('user', 'GET', "https://api.example.com/users/{$userId}");
$pool->add('orders', 'GET', "https://api.example.com/orders?user={$userId}");

$results = $pool->flush();
$user = $results->get('user')->toArray();
$orders = $results->get('orders')->toArray();
```

## When to use

Use this bundle when you need to call **multiple HTTP APIs** in a single request/command and want concurrency, rate limiting, retry or reactive chaining without managing `stream()` manually.

Don't use it for a single HTTP call: `HttpClientInterface` is perfectly fine on its own.

## Quickstart

```php
use Aubes\HttpPoolBundle\Pool\PoolFactoryInterface;
use Aubes\HttpPoolBundle\Pool\PoolInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class MyService
{
    public function __construct(
        private readonly PoolFactoryInterface $httpPool,
    ) {}

    public function fetchUserData(int $userId): array
    {
        $pool = $this->httpPool->create(concurrency: 10);

        $pool->add('user', 'GET', "https://api.example.com/users/{$userId}")
            ->then(function (ResponseInterface $response, PoolInterface $pool) {
                $user = $response->toArray();

                // Fan-out: callbacks can add requests to the pool
                $pool->add('orders', 'GET', "https://api.example.com/orders?user={$user['id']}");
                $pool->add('avatar', 'GET', $user['avatar_url']);
            });

        $results = $pool->flush();

        return [
            'user' => $results->get('user')->toArray(),
            'orders' => $results->get('orders')->toArray(),
            'avatar' => $results->get('avatar')->getContent(),
        ];
    }
}
```

`create()` returns a disposable, request-scoped pool. `flush()` executes all requests (including those added dynamically by callbacks) and returns the results.

## Features

### Bounded concurrency

The pool keeps at most N requests in flight simultaneously. Excess requests wait in a queue.

```php
$pool = $this->httpPool->create(concurrency: 5);

for ($i = 0; $i < 100; $i++) {
    $pool->add("item_{$i}", 'GET', "https://api.example.com/items/{$i}");
}

// 100 requests executed in batches of 5
$results = $pool->flush();
```

### Reactive chaining (fan-out)

`then()` receives the response and the pool. The callback can add new requests that will be processed within the same `flush()`.

```php
$pool->add('user', 'GET', 'https://api.example.com/users/42')
    ->then(function (ResponseInterface $response, PoolInterface $pool) {
        $user = $response->toArray();

        // Level 2: requests triggered by the response
        $pool->add('orders', 'GET', "https://api.example.com/orders?user={$user['id']}")
            ->then(function (ResponseInterface $response, PoolInterface $pool) {
                // Level 3: nest as deep as needed
                foreach ($response->toArray() as $order) {
                    $pool->add(
                        "invoice_{$order['id']}",
                        'GET',
                        "https://api.example.com/invoices/{$order['invoiceId']}",
                    );
                }
            });
    });

$results = $pool->flush();

// All responses are accessible in a flat structure
$user = $results->get('user')->toArray();
$orders = $results->get('orders')->toArray();
$invoice1 = $results->get('invoice_1')->toArray();
```

### Deduplication with `addOnce()`

`addOnce()` works like `add()` but with built-in deduplication. If the key already exists:

- **Pending or in flight**: returns the existing entry (`then()` callbacks accumulate)
- **Already completed**: executes the `then()` immediately with the cached response

Only one HTTP request is made, regardless of how many consumers register callbacks.

```php
// Two products share the same brand: only one HTTP request
$pool->add('product_1', 'GET', 'https://api.example.com/products/1')
    ->then(function (ResponseInterface $response, PoolInterface $pool) use (&$product1) {
        $data = $response->toArray();
        $pool->addOnce("brand_{$data['brandId']}", 'GET', "https://api.example.com/brands/{$data['brandId']}")
            ->then(function (ResponseInterface $response) use (&$product1) {
                $product1['brand'] = $response->toArray();
            });
    });

$pool->add('product_2', 'GET', 'https://api.example.com/products/2')
    ->then(function (ResponseInterface $response, PoolInterface $pool) use (&$product2) {
        $data = $response->toArray();
        // Same brandId: no new request, the then() receives the cached response
        $pool->addOnce("brand_{$data['brandId']}", 'GET', "https://api.example.com/brands/{$data['brandId']}")
            ->then(function (ResponseInterface $response) use (&$product2) {
                $product2['brand'] = $response->toArray();
            });
    });
```

Works well with Symfony Serializer denormalizers: pass the pool in the denormalization context, and each denormalizer schedules its sub-requests via `addOnce()`.

### Fire-and-forget

`fire()` sends a request without waiting for the response. Errors are logged but do not appear in the results.

```php
$pool->add('user', 'GET', 'https://api.example.com/users/42');
$pool->fire('POST', 'https://analytics.example.com/events', [
    'json' => ['event' => 'user_viewed', 'user_id' => 42],
]);

$results = $pool->flush();
// $results contains 'user' but not the fire-and-forget request
```

### Error handling

#### Global strategies

Three strategies via the `ErrorStrategy` enum:

```php
use Aubes\HttpPoolBundle\ErrorStrategy;

// Default: collect errors, flush() continues
$pool = $this->httpPool->create(errorStrategy: ErrorStrategy::Collect);

// Stop on the first unhandled error, cancel in-flight requests
$pool = $this->httpPool->create(errorStrategy: ErrorStrategy::StopOnFirst);

// Execute everything, then throw an aggregate PoolException
$pool = $this->httpPool->create(errorStrategy: ErrorStrategy::ThrowAll);
```

| Strategy | `flush()` returns | `flush()` throws |
|---|---|---|
| `Collect` | `PoolResults` with `getErrors()` | Never |
| `StopOnFirst` | `PoolResults` if no errors | The first error's exception |
| `ThrowAll` | `PoolResults` if no errors | `PoolException` with all errors |

#### Per-request catch

`catch()` handles an error individually. If the callback does not rethrow, the error is considered handled (not counted in `getErrors()`).

```php
$pool->add('primary', 'GET', 'https://api.example.com/primary')
    ->catch(function (\Throwable $e, PoolInterface $pool) {
        // Fallback: schedule an alternative request
        $pool->add('fallback', 'GET', 'https://api.example.com/fallback');
        // Does not rethrow: error handled
    });
```

With `addOnce()`, multiple consumers can each register their own `catch()`. All are executed independently.

#### HTTP errors vs callback errors

When a `then()` callback throws an exception (application bug, parsing error...), it is wrapped in a `CallbackException`. The original HTTP response remains accessible:

```php
use Aubes\HttpPoolBundle\Exception\CallbackException;

$results = $pool->flush();

foreach ($results->getErrors() as $key => $error) {
    if ($error instanceof CallbackException) {
        // Error in the callback code, not in the HTTP request
        $originalResponse = $error->getResponse(); // the successful HTTP response
        $cause = $error->getPrevious();             // the original exception
    } else {
        // HTTP error (timeout, 500, etc.)
    }
}
```

When multiple `then()` callbacks are registered on the same entry (via `addOnce()`), each callback runs independently. If the first one fails, the rest still execute.

### Retry

Configurable retry per status code with exponential backoff.

```php
// Via Symfony config (see Configuration)
// Or directly via create():
$pool = $this->httpPool->create(retry: [
    503 => 3,  // max 3 attempts on 503
]);
```

Retry is transparent: `then()` callbacks only run after a successful response. If all attempts fail, the error follows the standard path (`catch()` or `getErrors()`).

`Retry-After` header support (429) is configurable.

### Per-host rate limiting

Token bucket per host to respect third-party API limits.

```php
$pool = $this->httpPool->create(
    concurrency: 20,
    rateLimits: [
        'orders-api.internal' => 20,  // 20 req/s
        'users-api.internal' => 50,   // 50 req/s
    ],
);
```

Requests exceeding the limit are delayed automatically. Rate limiting applies between the queue and the concurrency slots.

## Configuration

```yaml
# config/packages/http_pool.yaml
http_pool:
    default_concurrency: 10
    error_strategy: collect  # collect | stop_on_first | throw_all
    max_retry_delay: 30000   # ms, 0 = no cap
    retry:
        503: { max: 3, delay: 500, multiplier: 2 }
        429: respect_retry_after
    rate_limits:
        'orders-api.internal': 20
        'users-api.internal': 50
```

Config values serve as defaults for `create()`. Each call to `create()` can override them.

### Using a specific HTTP client

By default, the bundle uses the root `http_client` service. Symfony's scoped clients work transparently: if you configured a scoped client with `base_uri: 'https://orders-api.internal'`, requests matching that host will automatically inherit its options (headers, auth, timeout...).

If you need a pool factory wired to a specific HTTP client (custom transport, dedicated mock, etc.), register your own service with a named alias:

```yaml
# config/services.yaml
services:
    app.orders_pool_factory:
        class: Aubes\HttpPoolBundle\Pool\PoolFactory
        autowire: true
        arguments:
            $httpClient: '@orders_api'
            $defaultConcurrency: 5

    Aubes\HttpPoolBundle\Pool\PoolFactoryInterface $ordersPoolFactory: '@app.orders_pool_factory'
```

Then inject it:

```php
use Symfony\Component\DependencyInjection\Attribute\Target;

public function __construct(
    #[Target('ordersPoolFactory')]
    private readonly PoolFactoryInterface $ordersPoolFactory,
) {}
```

## Profiler

In debug mode, the bundle registers a **Web Debug Toolbar panel** showing pool activity: request count, fan-out chains, deduplication hits, errors, and flush duration.

## License

MIT
