<?php

declare(strict_types=1);

namespace StatusHq\Support;

interface Clock
{
    /**
     * A monotonic reading in nanoseconds.
     *
     * Monotonic rather than wall time because it is what the CPU-share maths
     * divides by: an NTP step backwards mid-sample would otherwise produce a
     * negative window and a nonsense percentage.
     */
    public function monotonicNanos(): int;

    /** Wall-clock seconds — used only to decide whether a stored sample is too old. */
    public function unixSeconds(): int;
}
