<?php

declare(strict_types=1);

namespace StatusHq\Health;

interface Check
{
    /**
     * A stable identifier for this check.
     *
     * Consumers key history off it, so renaming one starts a fresh series and
     * loses the old one — treat it as part of the public contract.
     */
    public function name(): string;

    /** Human-readable name, shown on the monitor page. */
    public function label(): string;

    public function run(): CheckResult;
}
