<?php

declare(strict_types=1);

namespace StatusHq\Health\Checks;

use StatusHq\Health\Check;
use StatusHq\Health\CheckResult;

/**
 * Shared logic for the "a percentage crossed a line" checks.
 */
abstract class ThresholdCheck implements Check
{
    protected float $warnAbove;

    protected float $failAbove;

    public function warnWhenAbovePercentage(float $percent): static
    {
        $this->warnAbove = $percent;

        return $this;
    }

    public function failWhenAbovePercentage(float $percent): static
    {
        $this->failAbove = $percent;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function evaluate(?float $value, string $metaKey, string $unavailableReason, array $meta = []): CheckResult
    {
        if ($value === null) {
            // Skipped, not failed. An unmeasurable metric says nothing about
            // the application's health, and paging on "we could not look" is
            // how a monitor teaches its owner to ignore it.
            return CheckResult::skipped($this->name(), $this->label(), $unavailableReason, $meta);
        }

        $meta = [$metaKey => $value] + $meta;
        $summary = $value.'%';

        if ($value >= $this->failAbove) {
            return CheckResult::failed(
                $this->name(),
                $this->label(),
                sprintf('%s is at %s%% (fails above %s%%)', $this->label(), $value, $this->failAbove),
                $summary,
                $meta,
            );
        }

        if ($value >= $this->warnAbove) {
            return CheckResult::warning(
                $this->name(),
                $this->label(),
                sprintf('%s is at %s%% (warns above %s%%)', $this->label(), $value, $this->warnAbove),
                $summary,
                $meta,
            );
        }

        return CheckResult::ok($this->name(), $this->label(), $summary, $meta);
    }
}
