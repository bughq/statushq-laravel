<?php

declare(strict_types=1);

namespace StatusHq\Health\Checks;

use StatusHq\Health\CheckResult;
use StatusHq\Metrics\DiskReader;

final class UsedDiskSpaceCheck extends ThresholdCheck
{
    public function __construct(
        private readonly DiskReader $disk = new DiskReader(),
        private readonly string $path = '/',
        float $warnAbove = 70,
        float $failAbove = 90,
    ) {
        $this->warnAbove = $warnAbove;
        $this->failAbove = $failAbove;
    }

    public function name(): string
    {
        // Deliberately the name spatie/laravel-health uses. A team migrating
        // from Oh Dear keeps its history instead of starting a new series.
        return 'UsedDiskSpace';
    }

    public function label(): string
    {
        return 'Used disk space';
    }

    public function run(): CheckResult
    {
        $usage = $this->disk->read($this->path);

        return $this->evaluate(
            $usage?->percent(),
            'disk_space_used_percentage',
            sprintf('%s could not be stat-ed', $this->path),
            ['path' => $this->path],
        );
    }
}
