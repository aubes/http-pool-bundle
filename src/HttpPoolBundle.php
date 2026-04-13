<?php

declare(strict_types=1);

namespace Aubes\HttpPoolBundle;

use Aubes\HttpPoolBundle\DataCollector\HttpPoolDataCollector;
use Aubes\HttpPoolBundle\DataCollector\TraceablePoolFactory;
use Aubes\HttpPoolBundle\Pool\PoolFactory;
use Aubes\HttpPoolBundle\Pool\PoolFactoryInterface;
use Aubes\HttpPoolBundle\Retry\RetryStrategy;
use Aubes\HttpPoolBundle\Retry\RetryStrategyInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class HttpPoolBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->integerNode('default_concurrency')
                    ->defaultValue(10)
                    ->min(1)
                ->end()
                ->enumNode('error_strategy')
                    ->values(['collect', 'stop_on_first', 'throw_all'])
                    ->defaultValue('collect')
                ->end()
                ->integerNode('max_retry_delay')
                    ->defaultValue(30000)
                    ->min(0)
                    ->info('Maximum retry delay in milliseconds. 0 = no cap.')
                ->end()
                ->arrayNode('retry')
                    ->useAttributeAsKey('status_code')
                    ->arrayPrototype()
                        ->beforeNormalization()
                            ->ifString()
                            ->then(static fn (string $v) => ['strategy' => $v])
                        ->end()
                        ->children()
                            ->stringNode('strategy')->defaultNull()->end()
                            ->integerNode('max')->defaultValue(3)->end()
                            ->integerNode('delay')->defaultValue(500)->end()
                            ->floatNode('multiplier')->defaultValue(2.0)->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('rate_limits')
                    ->useAttributeAsKey('host')
                    ->integerPrototype()->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $errorStrategy = match ($config['error_strategy']) {
            'stop_on_first' => ErrorStrategy::StopOnFirst,
            'throw_all' => ErrorStrategy::ThrowAll,
            default => ErrorStrategy::Collect,
        };

        $retryRules = [];
        $respectRetryAfter = false;

        /** @var array<int|string, array{strategy: string|null, max: int, delay: int, multiplier: float}> $retryConfigs */
        $retryConfigs = $config['retry'];

        foreach ($retryConfigs as $statusCode => $retryConfig) {
            if ('respect_retry_after' === $retryConfig['strategy']) {
                $respectRetryAfter = true;
            }

            $retryRules[(int) $statusCode] = [
                'max' => $retryConfig['max'],
                'delay' => $retryConfig['delay'],
                'multiplier' => $retryConfig['multiplier'],
            ];
        }

        if (!empty($retryRules)) {
            $services->set('http_pool.retry_strategy', RetryStrategy::class)
                ->args([
                    $retryRules,
                    $respectRetryAfter,
                    $config['max_retry_delay'],
                ]);
            $services->alias(RetryStrategyInterface::class, 'http_pool.retry_strategy');
        }

        $factoryArgs = [
            service('http_client'),
            $config['default_concurrency'],
            $errorStrategy,
            !empty($retryRules) ? service('http_pool.retry_strategy') : null,
            $config['rate_limits'],
            service('logger')->nullOnInvalid(),
        ];

        $services->set('http_pool.factory', PoolFactory::class)
            ->args($factoryArgs);
        $services->alias(PoolFactoryInterface::class, 'http_pool.factory');

        if ($builder->hasParameter('kernel.debug') && $builder->getParameter('kernel.debug')) {
            $services->set('http_pool.traceable_factory', TraceablePoolFactory::class)
                ->decorate('http_pool.factory')
                ->args([service('.inner')]);

            $services->set('http_pool.data_collector', HttpPoolDataCollector::class)
                ->args([service('http_pool.traceable_factory')])
                ->tag('data_collector', [
                    'template' => '@HttpPool/data_collector/pool.html.twig',
                    'id' => 'http_pool',
                ]);
        }
    }
}
