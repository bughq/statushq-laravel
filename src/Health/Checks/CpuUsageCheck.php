<?php

declare(strict_types=1);

namespace StatusHq\Health\Checks;

use StatusHq\Health\CheckResult;
use StatusHq\Metrics\CpuSampler;

/**
 * CPU in use as a percentage of what this container or host may use.
 *
 * Not the same thing as spatie/cpu-load-health-check, which reports the Unix
 * load average from sys_getloadavg(). Load average counts runnable processes
 * and is unbounded and core-count-relative — 8.0 is idle on a 16-core box and
 * a fire on a 2-core one. This is a bounded percentage, which is what the
 * StatusHQ ingest and its thresholds are defined in terms of.
 */
final class CpuUsageCheck extends ThresholdCheck
{
    public function __construct(
        private readonly CpuSampler $cpu = new CpuSampler(),
        float $warnAbove = 75,
        float $failAbove = 90,
    ) {
        $this->warnAbove = $warnAbove;
        $this->failAbove = $failAbove;
    }

    public function name(): string
    {
        return 'CpuUsage';
    }

    public function label(): string
    {
        return 'CPU usage';
    }

    public function run(): CheckResult
    {
        return $this->evaluate(
            $this->cpu->percent(),
            'cpu_used_percentage',
            'no previous sample to compare against yet — usage is a rate, so the first run cannot report one',
        );
    }
}
