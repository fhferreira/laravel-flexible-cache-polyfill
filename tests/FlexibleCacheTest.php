<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Spatie\FlexibleCache\Facades\FlexibleCache;
use Spatie\FlexibleCache\FlexibleCache as FlexibleCacheInstance;

beforeEach(function () {
    Cache::flush();
    Cache::store('redis2')->flush();

    Carbon::setTestNow(Carbon::now());
});

afterEach(function () {
    Carbon::setTestNow();

    config()->set('cache.default', 'redis');
});

/**
 * Switch to array driver for TTL-based tests.
 *
 * Redis TTL is based on real time, not Carbon's test time.
 * The array driver allows us to use Carbon::setTestNow() for time manipulation.
 */
function useArrayDriver(): void
{
    config()->set('cache.default', 'array');
    Cache::forgetDriver('array');
}

describe('fresh cache', function () {
    it('returns computed value when cache is empty', function () {
        $callCount = 0;

        $result = FlexibleCache::flexible('test-key', [5, 10], function () use (&$callCount) {
            $callCount++;

            return 'computed-value';
        });

        expect($result)->toBe('computed-value');
        expect($callCount)->toBe(1);
    });

    it('stores value with correct TTL', function () {
        FlexibleCache::flexible('test-key', [5, 10], fn () => 'test-value');

        expect(Cache::get('test-key'))->toBe('test-value');
        // Redis returns strings, so use toEqual for loose comparison
        expect(Cache::get('illuminate:cache:flexible:created:test-key'))->toEqual(Carbon::now()->getTimestamp());
    });

    it('returns cached value on subsequent calls within fresh period', function () {
        // Use array driver: Redis TTL is real-time based, not Carbon time
        useArrayDriver();

        $callCount = 0;

        FlexibleCache::flexible('test-key', [5, 10], function () use (&$callCount) {
            $callCount++;

            return 'first-value';
        });

        Carbon::setTestNow(Carbon::now()->addSeconds(3));

        $result = FlexibleCache::flexible('test-key', [5, 10], function () use (&$callCount) {
            $callCount++;

            return 'second-value';
        });

        expect($result)->toBe('first-value');
        expect($callCount)->toBe(1);
    });
});

describe('stale-while-revalidate', function () {
    beforeEach(function () {
        // Use array driver: these tests rely on Carbon time travel
        useArrayDriver();
    });

    it('returns stale value immediately when in stale window', function () {
        $callCount = 0;

        FlexibleCache::flexible('test-key', [5, 10], function () use (&$callCount) {
            $callCount++;

            return 'initial-value';
        });

        Carbon::setTestNow(Carbon::now()->addSeconds(7));

        $result = FlexibleCache::flexible('test-key', [5, 10], function () use (&$callCount) {
            $callCount++;

            return 'new-value';
        });

        expect($result)->toBe('initial-value');
        expect($callCount)->toBe(1);
    });

    it('schedules background refresh via terminating callback', function () {
        $initialTimestamp = Carbon::now()->getTimestamp();
        FlexibleCache::flexible('test-key', [5, 10], fn () => 'initial-value');

        Carbon::setTestNow(Carbon::now()->addSeconds(7));
        $staleTimestamp = Carbon::now()->getTimestamp();

        $newCallCount = 0;
        FlexibleCache::flexible('test-key', [5, 10], function () use (&$newCallCount) {
            $newCallCount++;

            return 'refreshed-value';
        });

        expect($newCallCount)->toBe(0);

        // Simulate app termination by running terminating callbacks
        app()->terminate();

        expect($newCallCount)->toBe(1);
        expect(Cache::get('test-key'))->toBe('refreshed-value');

        // Verify the created timestamp was also updated (critical for preventing infinite stale loops)
        $newTimestamp = Cache::get('illuminate:cache:flexible:created:test-key');
        expect($newTimestamp)->toBe($staleTimestamp);
        expect($newTimestamp)->not->toBe($initialTimestamp);
    });

    it('prevents duplicate refresh scheduling', function () {
        $instance = app(FlexibleCacheInstance::class);

        $instance->flexible('test-key', [5, 10], fn () => 'initial-value');

        Carbon::setTestNow(Carbon::now()->addSeconds(7));

        // Use DIFFERENT callbacks to verify only the first one runs
        // This matches Laravel's defer() behavior where first callback wins
        $callCount1 = 0;
        $callCount2 = 0;
        $callCount3 = 0;

        $instance->flexible('test-key', [5, 10], function () use (&$callCount1) {
            $callCount1++;

            return 'first-callback';
        });
        $instance->flexible('test-key', [5, 10], function () use (&$callCount2) {
            $callCount2++;

            return 'second-callback';
        });
        $instance->flexible('test-key', [5, 10], function () use (&$callCount3) {
            $callCount3++;

            return 'third-callback';
        });

        app()->terminate();

        // Only the first callback should have run
        expect($callCount1)->toBe(1);
        expect($callCount2)->toBe(0);
        expect($callCount3)->toBe(0);
        expect(Cache::get('test-key'))->toBe('first-callback');
    });
});

describe('expired cache', function () {
    beforeEach(function () {
        // Use array driver: these tests rely on Carbon time travel for expiration
        useArrayDriver();
    });

    it('recomputes value when beyond stale window', function () {
        FlexibleCache::flexible('test-key', [5, 10], fn () => 'initial-value');

        Carbon::setTestNow(Carbon::now()->addSeconds(15));

        $callCount = 0;
        $result = FlexibleCache::flexible('test-key', [5, 10], function () use (&$callCount) {
            $callCount++;

            return 'new-value';
        });

        expect($result)->toBe('new-value');
        expect($callCount)->toBe(1);
    });

    it('recomputes when created timestamp is missing', function () {
        Cache::put('test-key', 'orphan-value', 100);

        $result = FlexibleCache::flexible('test-key', [5, 10], fn () => 'new-value');

        expect($result)->toBe('new-value');
    });

    it('recomputes when value is missing but timestamp exists', function () {
        Cache::put('illuminate:cache:flexible:created:test-key', Carbon::now()->getTimestamp(), 100);

        $result = FlexibleCache::flexible('test-key', [5, 10], fn () => 'new-value');

        expect($result)->toBe('new-value');
    });
});

describe('lock behavior', function () {
    // Uses Redis for real lock testing

    it('uses cache lock to prevent concurrent refreshes', function () {
        // Use array driver: test relies on Carbon time travel
        useArrayDriver();

        FlexibleCache::flexible('test-key', [5, 10], fn () => 'initial-value');

        Carbon::setTestNow(Carbon::now()->addSeconds(7));

        $callCount = 0;
        FlexibleCache::flexible('test-key', [5, 10], function () use (&$callCount) {
            $callCount++;

            return 'refreshed-value';
        }, ['seconds' => 10]);

        app()->terminate();

        expect($callCount)->toBe(1);
    });

    it('skips refresh if another process already refreshed', function () {
        // Use array driver: test relies on Carbon time travel
        useArrayDriver();

        $instance = app(FlexibleCacheInstance::class);

        $originalTimestamp = Carbon::now()->getTimestamp();
        $instance->flexible('test-key', [5, 10], fn () => 'initial-value');

        Carbon::setTestNow(Carbon::now()->addSeconds(7));

        $refreshCount = 0;
        $instance->flexible('test-key', [5, 10], function () use (&$refreshCount) {
            $refreshCount++;

            return 'should-not-be-used';
        });

        // Simulate another process updating the cache before termination
        Cache::put('illuminate:cache:flexible:created:test-key', Carbon::now()->getTimestamp(), 100);
        Cache::put('test-key', 'updated-by-another-process', 100);

        app()->terminate();

        // Callback was NOT executed because created timestamp changed
        expect($refreshCount)->toBe(0);
        expect(Cache::get('test-key'))->toBe('updated-by-another-process');
    });
});

describe('TTL formats', function () {
    it('accepts integer seconds', function () {
        FlexibleCache::flexible('test-key', [5, 10], fn () => 'value');

        expect(Cache::get('test-key'))->toBe('value');
    });

    it('accepts DateInterval', function () {
        // Use array driver: test relies on Carbon time travel
        useArrayDriver();

        $fresh = new DateInterval('PT5S');
        $stale = new DateInterval('PT10S');

        FlexibleCache::flexible('test-key', [$fresh, $stale], fn () => 'value');

        expect(Cache::get('test-key'))->toBe('value');

        Carbon::setTestNow(Carbon::now()->addSeconds(7));

        $result = FlexibleCache::flexible('test-key', [$fresh, $stale], fn () => 'new-value');

        // Should return stale value
        expect($result)->toBe('value');
    });

    it('accepts DateTimeInterface', function () {
        $fresh = Carbon::now()->addSeconds(5);
        $stale = Carbon::now()->addSeconds(10);

        FlexibleCache::flexible('test-key', [$fresh, $stale], fn () => 'value');

        expect(Cache::get('test-key'))->toBe('value');
    });
});

describe('edge cases', function () {
    beforeEach(function () {
        // Use array driver: some tests rely on Carbon time travel
        useArrayDriver();
    });

    it('handles zero TTL values', function () {
        FlexibleCache::flexible('test-key', [0, 5], fn () => 'value');

        // Should immediately be stale (fresh period is 0 seconds)
        $result = FlexibleCache::flexible('test-key', [0, 5], fn () => 'new-value');

        // Returns stale value immediately
        expect($result)->toBe('value');

        // But background refresh should still happen
        app()->terminate();
        expect(Cache::get('test-key'))->toBe('new-value');
    });

    it('handles negative TTL values as zero', function () {
        $past = Carbon::now()->subSeconds(5);

        FlexibleCache::flexible('test-key', [$past, Carbon::now()->addSeconds(10)], fn () => 'value');

        expect(Cache::get('test-key'))->toBe('value');
    });

    it('works with different cache keys', function () {
        FlexibleCache::flexible('key-1', [5, 10], fn () => 'value-1');
        FlexibleCache::flexible('key-2', [5, 10], fn () => 'value-2');

        expect(Cache::get('key-1'))->toBe('value-1');
        expect(Cache::get('key-2'))->toBe('value-2');
    });

    it('can be resolved from container', function () {
        $instance = app(FlexibleCacheInstance::class);

        expect($instance)->toBeInstanceOf(FlexibleCacheInstance::class);
    });

    it('is a singleton', function () {
        $instance1 = app(FlexibleCacheInstance::class);
        $instance2 = app(FlexibleCacheInstance::class);

        expect($instance1)->toBe($instance2);
    });
});

describe('facade', function () {
    it('proxies to the FlexibleCache instance', function () {
        $result = FlexibleCache::flexible('facade-test', [5, 10], fn () => 'facade-value');

        expect($result)->toBe('facade-value');
        expect(Cache::get('facade-test'))->toBe('facade-value');
    });
});

describe('cache store', function () {
    // Uses Redis to test multi-store functionality with real Redis connections

    it('uses specified cache store', function () {
        FlexibleCache::store('redis2')->flexible('store-test', [5, 10], fn () => 'custom-value');

        // Value should be in redis2 store
        expect(Cache::store('redis2')->get('store-test'))->toBe('custom-value');

        // Value should NOT be in default store
        expect(Cache::get('store-test'))->toBeNull();
    });

    it('resets store after each call', function () {
        $instance = app(FlexibleCacheInstance::class);

        // First call with redis2 store
        $instance->store('redis2')->flexible('key-1', [5, 10], fn () => 'value-1');

        // Second call without store should use default
        $instance->flexible('key-2', [5, 10], fn () => 'value-2');

        expect(Cache::store('redis2')->get('key-1'))->toBe('value-1');
        expect(Cache::store('redis2')->get('key-2'))->toBeNull();
        expect(Cache::get('key-2'))->toBe('value-2');
    });

    it('uses specified store for background refresh', function () {
        // Use array driver for both stores: test relies on Carbon time travel
        config()->set('cache.default', 'array');
        config()->set('cache.stores.array2', ['driver' => 'array', 'serialize' => false]);
        Cache::forgetDriver('array');
        Cache::forgetDriver('array2');

        FlexibleCache::store('array2')->flexible('refresh-test', [5, 10], fn () => 'initial-value');

        Carbon::setTestNow(Carbon::now()->addSeconds(7));

        FlexibleCache::store('array2')->flexible('refresh-test', [5, 10], fn () => 'refreshed-value');

        app()->terminate();

        // Refreshed value should be in array2 store
        expect(Cache::store('array2')->get('refresh-test'))->toBe('refreshed-value');
    });

    it('works with null store (uses default)', function () {
        FlexibleCache::store(null)->flexible('null-store-test', [5, 10], fn () => 'default-value');

        expect(Cache::get('null-store-test'))->toBe('default-value');
    });
});

describe('Cache::flexible() macro', function () {
    it('works with default cache store', function () {
        $result = Cache::flexible('macro-test', [5, 10], fn () => 'macro-value');

        expect($result)->toBe('macro-value');
        expect(Cache::get('macro-test'))->toBe('macro-value');
    });

    it('works with specific cache store', function () {
        $result = Cache::store('redis2')->flexible('store-macro-test', [5, 10], fn () => 'store-value');

        expect($result)->toBe('store-value');
        expect(Cache::store('redis2')->get('store-macro-test'))->toBe('store-value');
        expect(Cache::get('store-macro-test'))->toBeNull();
    });

    it('returns stale value and refreshes in background with specific store', function () {
        // Use array driver for both stores: test relies on Carbon time travel
        config()->set('cache.default', 'array');
        config()->set('cache.stores.array2', ['driver' => 'array', 'serialize' => false]);
        Cache::forgetDriver('array');
        Cache::forgetDriver('array2');

        Cache::store('array2')->flexible('stale-macro-test', [5, 10], fn () => 'initial-value');

        Carbon::setTestNow(Carbon::now()->addSeconds(7));

        $result = Cache::store('array2')->flexible('stale-macro-test', [5, 10], fn () => 'refreshed-value');

        expect($result)->toBe('initial-value');

        app()->terminate();

        expect(Cache::store('array2')->get('stale-macro-test'))->toBe('refreshed-value');
    });

    it('stores created timestamp in the same store', function () {
        Cache::store('redis2')->flexible('timestamp-test', [5, 10], fn () => 'value');

        // Redis returns strings, so use toEqual for loose comparison
        expect(Cache::store('redis2')->get('illuminate:cache:flexible:created:timestamp-test'))->toEqual(Carbon::now()->getTimestamp());
        expect(Cache::get('illuminate:cache:flexible:created:timestamp-test'))->toBeNull();
    });
});
