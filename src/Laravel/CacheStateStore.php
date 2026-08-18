<?php

declare(strict_types=1);

namespace StatusHq\Laravel;

use Illuminate\Contracts\Cache\Repository;
use StatusHq\Support\StateStore;

/**
 * Keeps the previous CPU counter reading in Laravel's cache.
 *
 * The cache is the right home for it: the value is worthless after a few
 * minutes, losing it costs one skipped sample, and every deployment already
 * has one configured. It does need to be shared across processes though — an
 * `array` cache driver means the scheduled command never sees its own
 * previous run and CPU never reports.
 */
final class CacheStateStore implements StateStore
{
    public function __construct(private readonly Repository $cache)
    {
    }

    public function get(string $key): ?array
    {
        $value = $this->cache->get($key);

        return is_array($value) ? $value : null;
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        $this->cache->put($key, $value, $ttlSeconds);
    }
}
