<?php

declare(strict_types=1);

namespace StatusHq\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use StatusHq\Metrics\DiskUsage;
use StatusHq\Metrics\HostSample;
use StatusHq\Metrics\MemoryUsage;

final class HostSampleTest extends TestCase
{
    private function memory(): MemoryUsage
    {
        return new MemoryUsage(160 * 1024 * 1024, 512 * 1024 * 1024, MemoryUsage::SOURCE_CGROUP_V2);
    }

    public function test_the_payload_matches_the_ingest_contract(): void
    {
        $sample = new HostSample(42.5, $this->memory(), new DiskUsage(75, 100, '/'), 'web-01');

        $this->assertSame([
            'cpuPercent' => 42.5,
            'ramPercent' => 31.3,
            'ramUsedMb' => 160,
            'ramTotalMb' => 512,
            'host' => 'web-01',
            'diskPercent' => 75.0,
        ], $sample->toIngestPayload());
    }

    public function test_disk_is_omitted_rather_than_sent_as_zero(): void
    {
        $sample = new HostSample(42.5, $this->memory(), null, 'web-01');

        // The ingest treats absent disk as "this agent does not report disk"
        // and skips disk alerting entirely. Sending 0 would instead mean an
        // empty filesystem, which is a different and wrong claim.
        $this->assertArrayNotHasKey('diskPercent', $sample->toIngestPayload());
    }

    public function test_a_sample_without_cpu_is_not_reportable(): void
    {
        // The first scheduled run has no previous counter to difference
        // against. Skipping one minute beats inventing a number.
        $this->assertFalse((new HostSample(null, $this->memory(), null, 'web-01'))->isReportable());
        $this->assertFalse((new HostSample(42.5, null, null, 'web-01'))->isReportable());
        $this->assertTrue((new HostSample(42.5, $this->memory(), null, 'web-01'))->isReportable());
    }

    public function test_a_first_run_and_an_unmeasurable_host_are_told_apart(): void
    {
        // The bug this exists to prevent: a macOS box was told "the first run
        // cannot report one", which invites waiting for a next run that will
        // never differ. Nothing there exposes CPU counters at all.
        $firstRun = new HostSample(null, $this->memory(), null, 'web-01', cpuMeasurable: true);
        $unmeasurable = new HostSample(null, $this->memory(), null, 'macbook', cpuMeasurable: false);

        $this->assertStringContainsString('needs a previous sample', (string) $firstRun->whyNotReportable());
        $this->assertStringContainsString('no CPU counters', (string) $unmeasurable->whyNotReportable());
        $this->assertStringContainsString('Linux-only', (string) $unmeasurable->whyNotReportable());
    }

    public function test_unreadable_memory_is_named_as_the_reason(): void
    {
        $sample = new HostSample(42.5, null, null, 'macbook');

        $this->assertStringContainsString('memory', (string) $sample->whyNotReportable());
    }

    public function test_a_reportable_sample_has_no_reason(): void
    {
        $this->assertNull((new HostSample(42.5, $this->memory(), null, 'web-01'))->whyNotReportable());
    }

    public function test_display_rows_show_unmeasured_values_as_unmeasured(): void
    {
        // toIngestPayload() coerces these to 0 because the endpoint requires
        // numbers. Printing that to a human reads as "your machine is idle".
        $rows = (new HostSample(null, null, null, 'macbook', cpuMeasurable: false))->toDisplayRows();
        $values = array_column($rows, 1, 0);

        $this->assertSame('—', $values['cpu']);
        $this->assertSame('—', $values['memory']);
        $this->assertSame('—', $values['disk']);
        $this->assertSame('macbook', $values['host']);
    }

    public function test_display_rows_carry_the_memory_source(): void
    {
        $rows = (new HostSample(42.5, $this->memory(), null, 'web-01'))->toDisplayRows();
        $values = array_column($rows, 1, 0);

        $this->assertSame('31.3%', $values['memory']);
        $this->assertSame('160 MB of 512 MB', $values['memory used']);
        $this->assertSame('cgroup-v2', $values['memory source']);
    }
}
