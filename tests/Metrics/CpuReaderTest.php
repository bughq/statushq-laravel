<?php

declare(strict_types=1);

namespace StatusHq\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use StatusHq\Metrics\CpuReader;
use StatusHq\Metrics\CpuSnapshot;
use StatusHq\Support\ArrayFileReader;
use StatusHq\Tests\FakeClock;
use StatusHq\Tests\Fixtures;

final class CpuReaderTest extends TestCase
{
    public function test_proc_stat_counts_iowait_as_idle(): void
    {
        // A process blocked on disk leaves the CPU free. Folding iowait into
        // busy is how a slow disk gets misreported as a hot processor.
        [$busy, $total] = CpuReader::parseProcStat(Fixtures::PROC_STAT);

        $this->assertSame(165000.0, $busy);
        $this->assertSame(1073000.0, $total);
    }

    public function test_guest_time_is_not_counted_twice(): void
    {
        // The kernel already includes guest and guest_nice in user and nice.
        // Summing the whole line inflates total and understates the result.
        [, $total] = CpuReader::parseProcStat(Fixtures::PROC_STAT);

        $this->assertSame(1073000.0, $total, 'guest (500) and guest_nice (100) must not be added again');
    }

    public function test_a_truncated_cpu_line_is_rejected_rather_than_guessed(): void
    {
        $this->assertNull(CpuReader::parseProcStat("cpu  100 200\nintr 5\n"));
        $this->assertNull(CpuReader::parseProcStat("intr 5\nctxt 6\n"));
    }

    public function test_a_cgroup_v2_quota_reads_as_a_share_of_a_core(): void
    {
        $this->assertSame(0.5, CpuReader::parseCgroupV2Quota('50000 100000'));
        $this->assertSame(2.0, CpuReader::parseCgroupV2Quota("200000 100000\n"));
    }

    public function test_an_unquotaed_cgroup_reports_no_share(): void
    {
        // Without a quota there is no ceiling to be a percentage of, so the
        // reader falls through to /proc/stat rather than inventing one.
        $this->assertNull(CpuReader::parseCgroupV2Quota('max 100000'));
        $this->assertNull(CpuReader::parseCgroupV2Quota(null));
        $this->assertNull(CpuReader::parseCgroupV2Quota('0 100000'));
    }

    public function test_a_quotaed_container_is_measured_against_its_quota(): void
    {
        $files = new ArrayFileReader([
            '/sys/fs/cgroup/cpu.stat' => Fixtures::CGROUP_V2_CPU_STAT,
            '/sys/fs/cgroup/cpu.max' => '50000 100000',
            '/proc/stat' => Fixtures::PROC_STAT,
        ]);
        $clock = new FakeClock(monotonicNanos: 20_000_000_000);

        $snapshot = (new CpuReader($files, $clock))->snapshot();

        $this->assertSame(CpuSnapshot::SOURCE_CGROUP_V2, $snapshot?->source);
        $this->assertSame(4_200_000.0, $snapshot->busy);
        // 20s of wall time at half a core = 10s of CPU it was entitled to.
        $this->assertSame(10_000_000.0, $snapshot->capacity);
    }

    public function test_an_unquotaed_container_falls_through_to_proc_stat(): void
    {
        $files = new ArrayFileReader([
            '/sys/fs/cgroup/cpu.stat' => Fixtures::CGROUP_V2_CPU_STAT,
            '/sys/fs/cgroup/cpu.max' => 'max 100000',
            '/proc/stat' => Fixtures::PROC_STAT,
        ]);

        $snapshot = (new CpuReader($files, new FakeClock()))->snapshot();

        $this->assertSame(CpuSnapshot::SOURCE_PROC_STAT, $snapshot?->source);
    }

    public function test_cgroup_v1_quota_of_minus_one_means_unlimited(): void
    {
        $files = new ArrayFileReader([
            '/sys/fs/cgroup/cpuacct/cpuacct.usage' => '4200000000',
            '/sys/fs/cgroup/cpu/cpu.cfs_quota_us' => '-1',
            '/sys/fs/cgroup/cpu/cpu.cfs_period_us' => '100000',
            '/proc/stat' => Fixtures::PROC_STAT,
        ]);

        $this->assertSame(CpuSnapshot::SOURCE_PROC_STAT, (new CpuReader($files, new FakeClock()))->snapshot()?->source);
    }

    public function test_cgroup_v1_usage_is_converted_from_nanoseconds(): void
    {
        $files = new ArrayFileReader([
            '/sys/fs/cgroup/cpuacct/cpuacct.usage' => '4200000000',
            '/sys/fs/cgroup/cpu/cpu.cfs_quota_us' => '50000',
            '/sys/fs/cgroup/cpu/cpu.cfs_period_us' => '100000',
        ]);

        $snapshot = (new CpuReader($files, new FakeClock(monotonicNanos: 20_000_000_000)))->snapshot();

        $this->assertSame(CpuSnapshot::SOURCE_CGROUP_V1, $snapshot?->source);
        $this->assertSame(4_200_000.0, $snapshot->busy, 'cpuacct.usage is nanoseconds; the rest of the class works in microseconds');
    }

    public function test_no_readable_source_is_null(): void
    {
        $this->assertNull((new CpuReader(new ArrayFileReader(), new FakeClock()))->snapshot());
    }
}
