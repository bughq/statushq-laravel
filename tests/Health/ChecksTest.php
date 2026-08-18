<?php

declare(strict_types=1);

namespace StatusHq\Tests\Health;

use PHPUnit\Framework\TestCase;
use StatusHq\Health\CheckResult;
use StatusHq\Health\Checks\CpuUsageCheck;
use StatusHq\Health\Checks\UsedDiskSpaceCheck;
use StatusHq\Health\Checks\UsedMemoryCheck;
use StatusHq\Metrics\CpuReader;
use StatusHq\Metrics\CpuSampler;
use StatusHq\Metrics\DiskReader;
use StatusHq\Metrics\MemoryReader;
use StatusHq\Support\ArrayFileReader;
use StatusHq\Support\ArrayStateStore;
use StatusHq\Tests\FakeClock;
use StatusHq\Tests\MutableFileReader;

final class ChecksTest extends TestCase
{
    private const MB = 1024 * 1024;

    private function memoryAt(int $usedMb, int $limitMb): MemoryReader
    {
        return new MemoryReader(new ArrayFileReader([
            '/sys/fs/cgroup/memory.max' => (string) ($limitMb * self::MB),
            '/sys/fs/cgroup/memory.current' => (string) ($usedMb * self::MB),
        ]));
    }

    private function diskAt(float $percentUsed): DiskReader
    {
        return new DiskReader(
            static fn (): float => 100.0,
            static fn (): float => 100.0 - $percentUsed,
        );
    }

    public function test_memory_below_both_thresholds_is_ok(): void
    {
        $result = (new UsedMemoryCheck($this->memoryAt(256, 1024)))->run();

        $this->assertSame(CheckResult::STATUS_OK, $result->status);
        $this->assertSame('25%', $result->shortSummary);
        $this->assertSame('', $result->notificationMessage);
    }

    public function test_memory_over_the_warning_line_is_a_warning_not_a_failure(): void
    {
        // Degraded, not down. Paging on 85% memory is how a team learns to
        // mute the monitor before the 96% that mattered.
        $result = (new UsedMemoryCheck($this->memoryAt(870, 1024)))->run();

        $this->assertSame(CheckResult::STATUS_WARNING, $result->status);
        $this->assertStringContainsString('warns above 80%', $result->notificationMessage);
    }

    public function test_memory_over_the_failure_line_is_a_failure(): void
    {
        $result = (new UsedMemoryCheck($this->memoryAt(1000, 1024)))->run();

        $this->assertSame(CheckResult::STATUS_FAILED, $result->status);
        $this->assertSame(97.7, $result->meta['memory_used_percentage']);
    }

    public function test_memory_reports_which_interface_answered(): void
    {
        // A container reporting the host's memory is this package's headline
        // failure mode; `source` is how you tell from the outside.
        $result = (new UsedMemoryCheck($this->memoryAt(256, 1024)))->run();

        $this->assertSame('cgroup-v2', $result->meta['source']);
        $this->assertSame(1024, $result->meta['memory_total_mb']);
    }

    public function test_unreadable_memory_is_skipped_rather_than_failed(): void
    {
        // Developing on macOS, or a container with open_basedir clamped down.
        // "We could not look" is not evidence of ill health.
        $result = (new UsedMemoryCheck(new MemoryReader(new ArrayFileReader())))->run();

        $this->assertSame(CheckResult::STATUS_SKIPPED, $result->status);
        $this->assertSame('unavailable', $result->shortSummary);
        $this->assertStringContainsString('only readable on Linux', $result->notificationMessage);
    }

    public function test_thresholds_are_configurable(): void
    {
        $check = (new UsedMemoryCheck($this->memoryAt(600, 1024)))
            ->warnWhenAbovePercentage(50)
            ->failWhenAbovePercentage(55);

        $this->assertSame(CheckResult::STATUS_FAILED, $check->run()->status);
    }

    public function test_disk_keeps_the_name_oh_dear_users_already_have(): void
    {
        // Same check name spatie/laravel-health uses, so a team migrating
        // keeps its history instead of starting a new series.
        $check = new UsedDiskSpaceCheck($this->diskAt(91));

        $this->assertSame('UsedDiskSpace', $check->name());
        $result = $check->run();
        $this->assertSame(CheckResult::STATUS_FAILED, $result->status);
        $this->assertSame(91.0, $result->meta['disk_space_used_percentage']);
        $this->assertSame('/', $result->meta['path']);
    }

    public function test_an_unstattable_mount_is_skipped(): void
    {
        $check = new UsedDiskSpaceCheck(
            new DiskReader(static fn (): false => false, static fn (): false => false),
            '/mnt/gone',
        );

        $result = $check->run();

        $this->assertSame(CheckResult::STATUS_SKIPPED, $result->status);
        $this->assertStringContainsString('/mnt/gone', $result->notificationMessage);
    }

    public function test_cpu_is_skipped_on_the_first_run_and_reported_on_the_second(): void
    {
        $files = new MutableFileReader(['/proc/stat' => "cpu  1000 0 0 9000 0 0 0 0\n"]);
        $clock = new FakeClock();
        $sampler = new CpuSampler(new CpuReader($files, $clock), new ArrayStateStore(), $clock);
        $check = new CpuUsageCheck($sampler);

        $first = $check->run();

        $this->assertSame(CheckResult::STATUS_SKIPPED, $first->status);
        $this->assertStringContainsString('usage is a rate', $first->notificationMessage);

        $files->files['/proc/stat'] = "cpu  1095 0 0 9005 0 0 0 0\n";
        $clock->advance(60);

        $second = $check->run();

        $this->assertSame(CheckResult::STATUS_FAILED, $second->status);
        $this->assertSame(95.0, $second->meta['cpu_used_percentage']);
    }
}
