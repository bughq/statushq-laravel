<?php

declare(strict_types=1);

namespace StatusHq\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use StatusHq\Metrics\MemoryReader;
use StatusHq\Metrics\MemoryUsage;
use StatusHq\Support\ArrayFileReader;
use StatusHq\Tests\Fixtures;

final class MemoryReaderTest extends TestCase
{
    private const MB = 1024 * 1024;

    /** A 512 MB container: 256 MB in use, 96 MB of it reclaimable page cache. */
    private function containerV2(): ArrayFileReader
    {
        return new ArrayFileReader([
            '/sys/fs/cgroup/memory.max' => (string) (512 * self::MB),
            '/sys/fs/cgroup/memory.current' => (string) (256 * self::MB),
            '/sys/fs/cgroup/memory.stat' => Fixtures::CGROUP_V2_MEMORY_STAT,
            '/proc/meminfo' => Fixtures::MEMINFO,
        ]);
    }

    public function test_the_cgroup_limit_wins_over_the_hosts_memory(): void
    {
        // The bug this class exists for: /proc/meminfo describes the 64 GB
        // box, so a 512 MB container reading it reports 70% used when it is
        // actually at 31% of what it may use — or the reverse, sailing past
        // its own limit while claiming to be fine.
        $usage = (new MemoryReader($this->containerV2()))->read();

        $this->assertNotNull($usage);
        $this->assertSame(MemoryUsage::SOURCE_CGROUP_V2, $usage->source);
        $this->assertSame(512, $usage->totalMegabytes());
        $this->assertNotSame(64257, $usage->totalMegabytes());
    }

    public function test_reclaimable_page_cache_is_not_counted_as_used(): void
    {
        // 256 MB current − 96 MB inactive_file. Counting the cache would put
        // every long-running container at ~100% forever, because Linux fills
        // it with anything it reads and gives it back on demand.
        $usage = (new MemoryReader($this->containerV2()))->read();

        $this->assertSame(160, $usage?->usedMegabytes());
        $this->assertSame(31.3, $usage?->percent());
    }

    public function test_an_unlimited_cgroup_v2_falls_through_to_meminfo(): void
    {
        $files = $this->containerV2()->with('/sys/fs/cgroup/memory.max', "max\n");

        $usage = (new MemoryReader($files))->read();

        $this->assertSame(MemoryUsage::SOURCE_MEMINFO, $usage?->source);
    }

    public function test_cgroup_v1_is_read_when_v2_is_absent(): void
    {
        $files = new ArrayFileReader([
            '/sys/fs/cgroup/memory/memory.limit_in_bytes' => (string) (512 * self::MB),
            '/sys/fs/cgroup/memory/memory.usage_in_bytes' => (string) (256 * self::MB),
            '/sys/fs/cgroup/memory/memory.stat' => Fixtures::CGROUP_V1_MEMORY_STAT,
        ]);

        $usage = (new MemoryReader($files))->read();

        $this->assertSame(MemoryUsage::SOURCE_CGROUP_V1, $usage?->source);
        $this->assertSame(160, $usage?->usedMegabytes());
    }

    public function test_the_v1_unlimited_sentinel_is_not_treated_as_a_limit(): void
    {
        // Docker writes a near-PHP_INT_MAX byte count for "no limit". Taken
        // literally it makes every container look 0.000001% used.
        $files = new ArrayFileReader([
            '/sys/fs/cgroup/memory/memory.limit_in_bytes' => '9223372036854771712',
            '/sys/fs/cgroup/memory/memory.usage_in_bytes' => (string) (256 * self::MB),
            '/proc/meminfo' => Fixtures::MEMINFO,
        ]);

        $usage = (new MemoryReader($files))->read();

        $this->assertSame(MemoryUsage::SOURCE_MEMINFO, $usage?->source);
    }

    public function test_meminfo_uses_mem_available_rather_than_mem_free(): void
    {
        $usage = (new MemoryReader(new ArrayFileReader(['/proc/meminfo' => Fixtures::MEMINFO])))->read();

        // 65798616 − 19603868 kB used. MemFree alone would have said 96%.
        $this->assertSame(70.2, $usage?->percent());
    }

    public function test_meminfo_without_mem_available_adds_the_reclaimable_pools(): void
    {
        $files = new ArrayFileReader(['/proc/meminfo' => Fixtures::MEMINFO_WITHOUT_AVAILABLE]);

        $usage = (new MemoryReader($files))->read();

        // free + buffers + cached + reclaimable slab, which is what
        // MemAvailable approximates on kernels that publish it.
        $this->assertSame(64.6, $usage?->percent());
    }

    public function test_nothing_readable_is_null_rather_than_zero(): void
    {
        // macOS, Windows, or a container with open_basedir clamped down.
        $this->assertNull((new MemoryReader(new ArrayFileReader()))->read());
    }

    public function test_a_garbled_cgroup_file_does_not_produce_a_number(): void
    {
        $files = new ArrayFileReader([
            '/sys/fs/cgroup/memory.max' => 'not-a-number',
            '/sys/fs/cgroup/memory.current' => (string) (256 * self::MB),
        ]);

        $this->assertNull((new MemoryReader($files))->read());
    }

    public function test_usage_above_the_limit_is_clamped_to_one_hundred(): void
    {
        // Briefly possible under memory pressure before the OOM killer acts.
        $files = new ArrayFileReader([
            '/sys/fs/cgroup/memory.max' => (string) (512 * self::MB),
            '/sys/fs/cgroup/memory.current' => (string) (600 * self::MB),
        ]);

        $this->assertSame(100.0, (new MemoryReader($files))->read()?->percent());
    }

    public function test_parsers_are_exposed_for_fixtures(): void
    {
        $this->assertSame(19603868 * 1024, MemoryReader::parseMeminfoBytes(Fixtures::MEMINFO, 'MemAvailable'));
        $this->assertNull(MemoryReader::parseMeminfoBytes(Fixtures::MEMINFO, 'Nonsense'));
        $this->assertSame(100663296, MemoryReader::parseStatValue(Fixtures::CGROUP_V2_MEMORY_STAT, 'inactive_file'));
        $this->assertNull(MemoryReader::parseStatValue(Fixtures::CGROUP_V2_MEMORY_STAT, 'inactive_files'));
    }
}
