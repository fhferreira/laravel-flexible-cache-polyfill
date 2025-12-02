<?php

namespace Spatie\FlexibleCache\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Spatie\FlexibleCache\FlexibleCache store(?string $store)
 * @method static mixed flexible(string $key, array $ttl, callable $callback, ?array $lock = null)
 *
 * @see \Spatie\FlexibleCache\FlexibleCache
 */
class FlexibleCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Spatie\FlexibleCache\FlexibleCache::class;
    }
}
