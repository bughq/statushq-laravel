<?php

declare(strict_types=1);

namespace StatusHq\Health;

use StatusHq\Support\Clock;
use StatusHq\Support\SystemClock;
use Throwable;

final class Runner
{
    public function __construct(private readonly Clock $clock = new SystemClock())
    {
    }

    /**
     * @param  iterable<Check>  $checks
     */
    public function run(iterable $checks): Report
    {
        $results = [];

        foreach ($checks as $check) {
            $results[] = $this->runOne($check);
        }

        return new Report($this->clock->unixSeconds(), $results);
    }

    /**
     * A check that throws becomes `crashed` rather than a 500.
     *
     * One broken check must not blind the monitor to the other twelve — and a
     * health endpoint that returns 500 when a database check throws is
     * indistinguishable from the application being down, which loses the
     * detail the endpoint exists to carry.
     */
    private function runOne(Check $check): CheckResult
    {
        try {
            return $check->run();
        } catch (Throwable $exception) {
            return CheckResult::crashed(
                $check->name(),
                $check->label(),
                $exception->getMessage(),
                ['exception' => $exception::class],
            );
        }
    }
}
