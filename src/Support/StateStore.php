<?php

declare(strict_types=1);

namespace StatusHq\Support;

/**
 * Somewhere to keep the previous CPU counter reading between runs.
 *
 * CPU usage is a rate, and every source of it (/proc/stat, cgroup cpu.stat)
 * exposes a counter that only ever grows. A single reading is therefore the
 * machine's average since boot, which on a box that has been idle all week and
 * is pinned right now reads as roughly zero.
 *
 * Two readings are needed, and the honest place to take the second one is the
 * next scheduled run — not a sleep() in the middle of a web request. That
 * means the first reading has to outlive the process that took it.
 */
interface StateStore
{
    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * @param  array<string, mixed>  $value
     */
    public function put(string $key, array $value, int $ttlSeconds): void;
}
