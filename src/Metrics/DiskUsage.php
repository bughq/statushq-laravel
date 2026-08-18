<?php

declare(strict_types=1);

namespace StatusHq\Metrics;

final class DiskUsage
{
    public function __construct(
        public readonly int $usedBytes,
        public readonly int $totalBytes,
        public readonly string $path,
    ) {
    }

    public function percent(): float
    {
        if ($this->totalBytes <= 0) {
            return 0.0;
        }

        return round(min(100, max(0, $this->usedBytes / $this->totalBytes * 100)), 1);
    }
}
