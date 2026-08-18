<?php

declare(strict_types=1);

namespace StatusHq\Support;

final class SystemClock implements Clock
{
    public function monotonicNanos(): int
    {
        // hrtime() is CLOCK_MONOTONIC, which counts from boot and is shared by
        // every process on the host. That is what makes it comparable across
        // two separate `artisan` invocations a minute apart.
        return hrtime(true);
    }

    public function unixSeconds(): int
    {
        return time();
    }
}
