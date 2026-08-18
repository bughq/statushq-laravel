<?php

declare(strict_types=1);

namespace StatusHq\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use StatusHq\Metrics\DiskReader;

final class DiskReaderTest extends TestCase
{
    private const GB = 1024 ** 3;

    public function test_usage_is_total_minus_free(): void
    {
        $reader = new DiskReader(
            static fn (): float => 100 * self::GB,
            static fn (): float => 25 * self::GB,
        );

        $usage = $reader->read('/');

        $this->assertSame(75.0, $usage?->percent());
        $this->assertSame('/', $usage->path);
    }

    public function test_an_unstattable_path_is_null_rather_than_full(): void
    {
        // disk_free_space() returns false for a path that does not exist. A
        // reader that coerced that to 0 would report 100% used and page
        // somebody at 3am over a typo in a config file.
        $reader = new DiskReader(
            static fn (): false => false,
            static fn (): false => false,
        );

        $this->assertNull($reader->read('/nope'));
    }

    public function test_free_space_exceeding_the_total_is_clamped(): void
    {
        // Seen on network mounts and some overlay filesystems.
        $reader = new DiskReader(
            static fn (): float => 10 * self::GB,
            static fn (): float => 40 * self::GB,
        );

        $this->assertSame(0.0, $reader->read('/')?->percent());
    }

    public function test_a_zero_byte_filesystem_is_not_divided_by(): void
    {
        $reader = new DiskReader(
            static fn (): float => 0.0,
            static fn (): float => 0.0,
        );

        $this->assertNull($reader->read('/'));
    }

    public function test_it_reads_a_real_path_without_shelling_out(): void
    {
        // The point of using the built-ins: no proc_open, so this works where
        // `df` is unavailable — hardened images, open_basedir, shared hosting.
        $usage = (new DiskReader())->read(sys_get_temp_dir());

        $this->assertNotNull($usage);
        $this->assertGreaterThan(0, $usage->totalBytes);
        $this->assertGreaterThanOrEqual(0.0, $usage->percent());
        $this->assertLessThanOrEqual(100.0, $usage->percent());
    }
}
