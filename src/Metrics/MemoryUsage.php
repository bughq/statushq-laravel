<?php

declare(strict_types=1);

namespace StatusHq\Metrics;

final class MemoryUsage
{
    public const SOURCE_CGROUP_V2 = 'cgroup-v2';

    public const SOURCE_CGROUP_V1 = 'cgroup-v1';

    public const SOURCE_MEMINFO = 'proc-meminfo';

    public function __construct(
        public readonly int $usedBytes,
        public readonly int $totalBytes,
        /** Which kernel interface answered — reported in check meta so a wrong number is traceable. */
        public readonly string $source,
    ) {
    }

    public function percent(): float
    {
        if ($this->totalBytes <= 0) {
            return 0.0;
        }

        return round(min(100, max(0, $this->usedBytes / $this->totalBytes * 100)), 1);
    }

    public function usedMegabytes(): int
    {
        return (int) round($this->usedBytes / 1024 / 1024);
    }

    public function totalMegabytes(): int
    {
        return (int) round($this->totalBytes / 1024 / 1024);
    }
}
