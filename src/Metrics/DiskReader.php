<?php

declare(strict_types=1);

namespace StatusHq\Metrics;

use Closure;

/**
 * Disk usage for a mount point.
 *
 * disk_total_space()/disk_free_space() are PHP built-ins backed by statvfs,
 * so nothing is shelled out. That is the difference that matters in practice:
 * spatie/laravel-health runs `df -P` through Symfony Process, which needs
 * proc_open — routinely disabled on shared hosting, and absent from hardened
 * php-fpm images.
 */
final class DiskReader
{
    private Closure $totalSpace;

    private Closure $freeSpace;

    public function __construct(?Closure $totalSpace = null, ?Closure $freeSpace = null)
    {
        $this->totalSpace = $totalSpace ?? static fn (string $path): float|false => @disk_total_space($path);
        $this->freeSpace = $freeSpace ?? static fn (string $path): float|false => @disk_free_space($path);
    }

    /** Null when the path is not stat-able, rather than a guess at zero. */
    public function read(string $path = '/'): ?DiskUsage
    {
        $total = ($this->totalSpace)($path);
        $free = ($this->freeSpace)($path);

        if ($total === false || $free === false || $total <= 0) {
            return null;
        }

        $free = max(0.0, min((float) $total, (float) $free));

        return new DiskUsage((int) ($total - $free), (int) $total, $path);
    }
}
