<?php

namespace Spatie\FlexibleCache;

use Illuminate\Cache\Repository;
use Illuminate\Support\ServiceProvider;

class FlexibleCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FlexibleCache::class);
    }

    public function boot(): void
    {
        Repository::macro(
            'flexible',
            function (string $key, array $ttl, callable $callback, ?array $lock = null) {
                /** @var Repository $this */
                return app(FlexibleCache::class)
                    ->usingRepository($this)
                    ->flexible($key, $ttl, $callback, $lock);
            }
        );
    }
}
