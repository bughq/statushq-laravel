<?php

declare(strict_types=1);

namespace StatusHq\Support;

/**
 * An in-process store.
 *
 * Useful in two places: tests, and the blocking one-off sample taken by
 * `statushq:report --blocking`, where both readings happen inside the same
 * process and nothing needs to survive it.
 */
final class ArrayStateStore implements StateStore
{
    /** @var array<string, array<string, mixed>> */
    private array $values = [];

    public function get(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    public function put(string $key, array $value, int $ttlSeconds): void
    {
        // No expiry: nothing in this store outlives the process anyway, and
        // the caller's own staleness check is what keeps a long-dead sample
        // from being differenced.
        $this->values[$key] = $value;
    }
}
