<?php

declare(strict_types=1);

namespace StatusHq\Health\Checks;

use StatusHq\Health\CheckResult;
use StatusHq\Metrics\MemoryReader;

final class UsedMemoryCheck extends ThresholdCheck
{
    public function __construct(
        private readonly MemoryReader $memory = new MemoryReader(),
        float $warnAbove = 80,
        float $failAbove = 95,
    ) {
        $this->warnAbove = $warnAbove;
        $this->failAbove = $failAbove;
    }

    public function name(): string
    {
        return 'UsedMemory';
    }

    public function label(): string
    {
        return 'Used memory';
    }

    public function run(): CheckResult
    {
        $usage = $this->memory->read();

        return $this->evaluate(
            $usage?->percent(),
            'memory_used_percentage',
            'no cgroup or /proc/meminfo on this host — memory is only readable on Linux',
            $usage === null ? [] : [
                'memory_used_mb' => $usage->usedMegabytes(),
                'memory_total_mb' => $usage->totalMegabytes(),
                // Which interface answered. A container reporting the host's
                // 64 GB instead of its own 512 MB limit is the failure mode
                // this check exists to avoid, and `source` is how you see it.
                'source' => $usage->source,
            ],
        );
    }
}
