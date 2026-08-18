<?php

declare(strict_types=1);

namespace StatusHq\Tests;

use StatusHq\Support\Clock;

final class FakeClock implements Clock
{
    public function __construct(
        public int $monotonicNanos = 1_000_000_000,
        public int $unixSeconds = 1_700_000_000,
    ) {
    }

    public function monotonicNanos(): int
    {
        return $this->monotonicNanos;
    }

    public function unixSeconds(): int
    {
        return $this->unixSeconds;
    }

    /** Move both clocks forward together, the way real time does. */
    public function advance(int $seconds): void
    {
        $this->unixSeconds += $seconds;
        $this->monotonicNanos += $seconds * 1_000_000_000;
    }
}
