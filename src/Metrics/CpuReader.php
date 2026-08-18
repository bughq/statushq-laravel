<?php

declare(strict_types=1);

namespace StatusHq\Metrics;

use StatusHq\Support\Clock;
use StatusHq\Support\FileReader;
use StatusHq\Support\SystemClock;
use StatusHq\Support\SystemFileReader;

/**
 * Takes a single CPU counter reading, preferring the cgroup's own accounting
 * when the container has a CPU quota.
 *
 * The quota is what makes the difference worth the code. A container limited
 * to half a core on a 32-core host is at 100% of what it may use while
 * /proc/stat — which reports the whole host, since the kernel does not
 * namespace it — shows 1.5%. Without a quota there is nothing to be relative
 * to, so /proc/stat is both the honest answer and the only one.
 */
final class CpuReader
{
    private const CGROUP_V2_STAT = '/sys/fs/cgroup/cpu.stat';

    private const CGROUP_V2_MAX = '/sys/fs/cgroup/cpu.max';

    private const CGROUP_V1_USAGE = '/sys/fs/cgroup/cpuacct/cpuacct.usage';

    private const CGROUP_V1_QUOTA = '/sys/fs/cgroup/cpu/cpu.cfs_quota_us';

    private const CGROUP_V1_PERIOD = '/sys/fs/cgroup/cpu/cpu.cfs_period_us';

    private const PROC_STAT = '/proc/stat';

    public function __construct(
        private readonly FileReader $files = new SystemFileReader(),
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function snapshot(): ?CpuSnapshot
    {
        return $this->fromCgroupV2()
            ?? $this->fromCgroupV1()
            ?? $this->fromProcStat();
    }

    /**
     * Whether this host exposes CPU counters at all.
     *
     * The distinction the caller needs: "no reading yet" is transient and
     * resolves on the next run, while "nothing to read" is permanent and never
     * will. Telling a macOS developer to wait for the next sample sends them
     * away to wait for something that cannot happen.
     */
    public function isSupported(): bool
    {
        return $this->snapshot() !== null;
    }

    private function fromCgroupV2(): ?CpuSnapshot
    {
        $usageMicros = self::parseStatValue((string) $this->files->read(self::CGROUP_V2_STAT), 'usage_usec');
        $cores = self::parseCgroupV2Quota($this->files->read(self::CGROUP_V2_MAX));

        if ($usageMicros === null || $cores === null) {
            return null;
        }

        return new CpuSnapshot(
            (float) $usageMicros,
            $this->availableCpuMicros($cores),
            CpuSnapshot::SOURCE_CGROUP_V2,
            $this->clock->unixSeconds(),
        );
    }

    private function fromCgroupV1(): ?CpuSnapshot
    {
        $usageNanos = $this->intFrom($this->files->read(self::CGROUP_V1_USAGE));
        $quota = $this->intFrom($this->files->read(self::CGROUP_V1_QUOTA), allowNegative: true);
        $period = $this->intFrom($this->files->read(self::CGROUP_V1_PERIOD));

        // -1 is v1's spelling of "no quota"; without one there is no ceiling
        // to express usage as a fraction of.
        if ($usageNanos === null || $quota === null || $quota <= 0 || $period === null || $period <= 0) {
            return null;
        }

        return new CpuSnapshot(
            $usageNanos / 1000,
            $this->availableCpuMicros($quota / $period),
            CpuSnapshot::SOURCE_CGROUP_V1,
            $this->clock->unixSeconds(),
        );
    }

    private function fromProcStat(): ?CpuSnapshot
    {
        $contents = $this->files->read(self::PROC_STAT);

        if ($contents === null) {
            return null;
        }

        $totals = self::parseProcStat($contents);

        if ($totals === null) {
            return null;
        }

        [$busy, $total] = $totals;

        return new CpuSnapshot($busy, $total, CpuSnapshot::SOURCE_PROC_STAT, $this->clock->unixSeconds());
    }

    /** Cumulative CPU-microseconds this cgroup is entitled to, as of now. */
    private function availableCpuMicros(float $cores): float
    {
        return $this->clock->monotonicNanos() / 1000 * $cores;
    }

    /**
     * Cores allowed by cgroup v2's `cpu.max`, formatted "<quota|max> <period>".
     * Null when unquotaed. Exposed for tests.
     */
    public static function parseCgroupV2Quota(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($raw)) ?: [];

        if (count($parts) < 2 || $parts[0] === 'max') {
            return null;
        }

        $quota = (float) $parts[0];
        $period = (float) $parts[1];

        if ($quota <= 0 || $period <= 0) {
            return null;
        }

        return $quota / $period;
    }

    /**
     * Busy and total jiffies from the aggregate `cpu` line of /proc/stat.
     *
     * iowait counts as idle: the CPU is available during it, and folding it
     * into busy makes a slow disk look like a hot processor.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function parseProcStat(string $contents): ?array
    {
        if (preg_match('/^cpu\s+(.+)$/m', $contents, $matches) !== 1) {
            return null;
        }

        $fields = array_map('floatval', preg_split('/\s+/', trim($matches[1])) ?: []);

        // user, nice, system, idle — anything short of that is not the line we
        // think it is, and guessing at a truncated one would invent numbers.
        if (count($fields) < 4) {
            return null;
        }

        // Only the first eight columns (user…steal) are counted. `guest` and
        // `guest_nice` follow, and the kernel already includes them in `user`
        // and `nice` — summing the whole line counts guest time twice.
        $counted = array_slice($fields, 0, 8);

        $total = array_sum($counted);
        $idle = $counted[3] + ($counted[4] ?? 0.0);

        return [$total - $idle, $total];
    }

    /** Pull one `key value` pair out of a cgroup stat file. Exposed for tests. */
    public static function parseStatValue(string $contents, string $key): ?int
    {
        if (preg_match('/^'.preg_quote($key, '/').'\s+(\d+)$/m', $contents, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function intFrom(?string $raw, bool $allowNegative = false): ?int
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);
        $pattern = $allowNegative ? '/^-?\d+$/' : '/^\d+$/';

        return preg_match($pattern, $raw) === 1 ? (int) $raw : null;
    }
}
