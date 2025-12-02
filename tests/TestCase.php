<?php

namespace Spatie\FlexibleCache\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\FlexibleCache\FlexibleCacheServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            FlexibleCacheServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.redis.client', 'predis');

        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => 0,
        ]);

        // Secondary Redis connection for multi-store tests
        $app['config']->set('database.redis.cache2', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', 6379),
            'database' => 1,
        ]);

        $app['config']->set('cache.default', 'redis');

        $app['config']->set('cache.stores.redis', [
            'driver' => 'redis',
            'connection' => 'default',
            'lock_connection' => 'default',
        ]);

        // Secondary Redis store for multi-store tests
        $app['config']->set('cache.stores.redis2', [
            'driver' => 'redis',
            'connection' => 'cache2',
            'lock_connection' => 'cache2',
        ]);
    }
}
