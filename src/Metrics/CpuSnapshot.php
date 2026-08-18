<?php

declare(strict_types=1);

namespace StatusHq\Metrics;

/**
 * One reading of the CPU counters.
 *
 * `busy` and `capacity` are cumulative and share a unit, whatever that unit
 * is: jiffies from /proc/stat, microseconds from a cgroup. Only their deltas
 * are ever divided, so the unit cancels and neither source needs to know the
 * kernel's tick rate.
 */
final class CpuSnapshot
{
    public const SOURCE_CGROUP_V2 = 'cgroup-v2';

    public const SOURCE_CGROUP_V1 = 'cgroup-v1';

    public const SOURCE_PROC_STAT = 'proc-stat';

    public function __construct(
        public readonly float $busy,
        public readonly float $capacity,
        public readonly string $source,
        public readonly int $takenAtUnix,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'busy' => $this->busy,
            'capacity' => $this->capacity,
            'source' => $this->source,
            'taken_at' => $this->takenAtUnix,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function fromArray(array $state): ?self
    {
        if (! isset($state['busy'], $state['capacity'], $state['source'], $state['taken_at'])) {
            return null;
        }

        if (! is_numeric($state['busy']) || ! is_numeric($state['capacity']) || ! is_string($state['source']) || ! is_numeric($state['taken_at'])) {
            return null;
        }

        return new self(
            (float) $state['busy'],
            (float) $state['capacity'],
            $state['source'],
            (int) $state['taken_at'],
        );
    }
}
