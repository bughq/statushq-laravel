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
}
