<?php

declare(strict_types=1);

namespace StatusHq\Spatie;

use StatusHq\Health\Checks\CpuUsageCheck;
use StatusHq\Health\Checks\UsedDiskSpaceCheck;
use StatusHq\Health\Checks\UsedMemoryCheck;
use StatusHq\Metrics\CpuSampler;
use StatusHq\Metrics\DiskReader;
use StatusHq\Metrics\MemoryReader;

/**
 * The host checks, ready to hand to spatie/laravel-health:
 *
 *     Health::checks([
 *         ...HostChecks::all(),
 *         DatabaseCheck::new(),
 *     ]);
 *
 * What this adds to a stock spatie install: a memory check (it has none), a
 * CPU check expressed as a percentage of the container's quota rather than a
 * raw load average, and a disk check that reads statvfs instead of shelling
 * out to `df`.
 */
final class HostChecks
{
    /**
     * @return list<StatusHqCheckAdapter>
     */
    public static function all(string $diskPath = '/'): array
    {
        return [
            self::cpu(),
            self::memory(),
            self::disk($diskPath),
        ];
    }

    public static function cpu(): StatusHqCheckAdapter
    {
        return StatusHqCheckAdapter::for(new CpuUsageCheck(app(CpuSampler::class)));
    }

    public static function memory(): StatusHqCheckAdapter
    {
        return StatusHqCheckAdapter::for(new UsedMemoryCheck(app(MemoryReader::class)));
    }

    public static function disk(string $path = '/'): StatusHqCheckAdapter
    {
        return StatusHqCheckAdapter::for(new UsedDiskSpaceCheck(app(DiskReader::class), $path));
    }
}
