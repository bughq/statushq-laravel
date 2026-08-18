<?php

declare(strict_types=1);

namespace StatusHq\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use StatusHq\Metrics\CpuReader;
use StatusHq\Metrics\CpuSampler;
use StatusHq\Metrics\CpuSnapshot;
use StatusHq\Support\ArrayStateStore;
use StatusHq\Support\FileReader;
use StatusHq\Tests\FakeClock;
use StatusHq\Tests\MutableFileReader;

final class CpuSamplerTest extends TestCase
{
    private function procStat(float $busy, float $idle): string
    {
        // user, nice, system, idle, iowait, irq, softirq, steal
        return sprintf("cpu  %d 0 0 %d 0 0 0 0\nintr 1\n", $busy, $idle);
    }

    private function sampler(MutableFileReader $files, FakeClock $clock, ArrayStateStore $state, int $maxAge = 900): CpuSampler
    {
        return new CpuSampler(new CpuReader($files, $clock), $state, $clock, $maxAge);
    }

    public function test_the_first_run_reports_nothing_rather_than_zero(): void
    {
        // The counter is cumulative since boot. One reading is the machine's
        // lifetime average — and a fabricated 0% is indistinguishable from a
        // genuinely idle box, so nobody would ever catch it.
        $files = new MutableFileReader(['/proc/stat' => $this->procStat(1000, 9000)]);

        $this->assertNull($this->sampler($files, new FakeClock(), new ArrayStateStore())->percent());
    }

    public function test_the_second_run_reports_the_share_of_the_interval(): void
    {
        $files = new MutableFileReader(['/proc/stat' => $this->procStat(1000, 9000)]);
        $clock = new FakeClock();
        $state = new ArrayStateStore();
        $sampler = $this->sampler($files, $clock, $state);

        $sampler->percent();

        // 60 more jiffies busy out of 100 elapsed.
        $files->files['/proc/stat'] = $this->procStat(1060, 9040);
        $clock->advance(60);

        $this->assertSame(60.0, $sampler->percent());
    }

    public function test_a_failed_run_still_leaves_a_baseline(): void
    {
        // Otherwise a sampler that starts against a stale or mismatched
        // reading never recovers: every run discards, stores nothing usable,
        // and reports null forever.
        $files = new MutableFileReader(['/proc/stat' => $this->procStat(1000, 9000)]);
        $clock = new FakeClock();
        $state = new ArrayStateStore();
        $sampler = $this->sampler($files, $clock, $state);

        $sampler->percent();

        $this->assertNotNull($state->get(CpuSampler::STATE_KEY));
    }

    public function test_a_rebooted_counter_is_discarded(): void
    {
        $files = new MutableFileReader(['/proc/stat' => $this->procStat(50_000, 50_000)]);
        $clock = new FakeClock();
        $sampler = $this->sampler($files, $clock, new ArrayStateStore());

        $sampler->percent();

        // Counters restart at zero on boot, so the delta goes negative. A
        // wrapped subtraction here would report a wild percentage at exactly
        // the moment someone is watching the graph.
        $files->files['/proc/stat'] = $this->procStat(10, 90);
        $clock->advance(60);

        $this->assertNull($sampler->percent());
    }

    public function test_a_sample_older_than_the_window_is_not_differenced(): void
    {
        $files = new MutableFileReader(['/proc/stat' => $this->procStat(1000, 9000)]);
        $clock = new FakeClock();
        $sampler = $this->sampler($files, $clock, new ArrayStateStore(), maxAge: 900);

        $sampler->percent();

        $files->files['/proc/stat'] = $this->procStat(2000, 18_000);
        $clock->advance(3600);

        $this->assertNull($sampler->percent(), 'an hour-wide average is not a description of now');
    }

    public function test_a_source_change_is_not_differenced(): void
    {
        // Adding a CPU limit to a running deployment switches the reader from
        // jiffies to microseconds. Subtracting one from the other is garbage.
        $files = new MutableFileReader(['/proc/stat' => $this->procStat(1000, 9000)]);
        $clock = new FakeClock();
        $sampler = $this->sampler($files, $clock, new ArrayStateStore());

        $sampler->percent();

        $files->files['/sys/fs/cgroup/cpu.stat'] = "usage_usec 4200000\n";
        $files->files['/sys/fs/cgroup/cpu.max'] = '50000 100000';
        $clock->advance(60);

        $this->assertNull($sampler->percent());
    }

    public function test_a_pinned_container_reads_as_one_hundred_not_more(): void
    {
        $previous = new CpuSnapshot(0, 0, CpuSnapshot::SOURCE_CGROUP_V2, 1000);
        // Bursting above the quota for part of the window is normal; the
        // percentage still has to stay inside its range.
        $current = new CpuSnapshot(12_000_000, 10_000_000, CpuSnapshot::SOURCE_CGROUP_V2, 1060);

        $this->assertSame(100.0, CpuSampler::percentBetween($previous, $current));
    }

    public function test_two_readings_at_the_same_instant_are_not_divided(): void
    {
        $snapshot = new CpuSnapshot(1000, 5000, CpuSnapshot::SOURCE_PROC_STAT, 1000);

        $this->assertNull(CpuSampler::percentBetween($snapshot, $snapshot));
    }

    public function test_no_readable_source_reports_nothing(): void
    {
        $sampler = $this->sampler(new MutableFileReader(), new FakeClock(), new ArrayStateStore());

        $this->assertNull($sampler->percent());
    }

    public function test_a_corrupt_stored_sample_is_ignored(): void
    {
        $files = new MutableFileReader(['/proc/stat' => $this->procStat(1000, 9000)]);
        $state = new ArrayStateStore();
        $state->put(CpuSampler::STATE_KEY, ['busy' => 'nonsense'], 900);

        $this->assertNull($this->sampler($files, new FakeClock(), $state)->percent());
    }

    public function test_the_blocking_sample_reports_without_stored_state(): void
    {
        // The one-off CLI path: both readings inside one process, so nothing
        // has to survive it. Only safe off the request cycle, which is why it
        // is not the default.
        $advancing = new class implements FileReader
        {
            private int $reads = 0;

            public function read(string $path): ?string
            {
                if ($path !== '/proc/stat') {
                    return null;
                }

                // 25 busy jiffies of every 100 that elapse between reads.
                $busy = 1000 + (25 * $this->reads);
                $idle = 9000 + (75 * $this->reads);
                $this->reads++;

                return sprintf("cpu  %d 0 0 %d 0 0 0 0\n", $busy, $idle);
            }
        };

        $sampler = new CpuSampler(new CpuReader($advancing, new FakeClock()), new ArrayStateStore(), new FakeClock());

        $this->assertSame(25.0, $sampler->percentByBlockingSample(1));
    }

    public function test_a_window_in_which_nothing_moved_is_not_divided(): void
    {
        // Both reads land on identical counters — a paused VM, or a fixture.
        // Zero over zero is not 0%, it is unknown.
        $files = new MutableFileReader(['/proc/stat' => $this->procStat(1000, 9000)]);
        $sampler = $this->sampler($files, new FakeClock(), new ArrayStateStore());

        $this->assertNull($sampler->percentByBlockingSample(1));
    }
}
