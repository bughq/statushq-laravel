<?php

declare(strict_types=1);

namespace StatusHq\Metrics;

use StatusHq\Support\FileReader;
use StatusHq\Support\SystemFileReader;

/**
 * How much memory this application may use, and how much of it is in use.
 *
 * The cgroup files are consulted before /proc/meminfo, and that ordering is
 * the whole point of this class. Inside a container /proc/meminfo describes
 * the host: an app capped at 512 MB on a 64 GB box reads as 3% used while it
 * is actually being OOM-killed. Every "memory monitoring" that reads meminfo
 * unconditionally is wrong on Docker, Kubernetes, Fly, ECS and Cloud Run.
 *
 * Used memory excludes the inactive file cache, matching what kubelet calls
 * the working set. Page cache is reclaimable under pressure, so counting it
 * would report a healthy long-running container at ~100% forever.
 */
final class MemoryReader
{
    private const CGROUP_V2_MAX = '/sys/fs/cgroup/memory.max';

    private const CGROUP_V2_CURRENT = '/sys/fs/cgroup/memory.current';

    private const CGROUP_V2_STAT = '/sys/fs/cgroup/memory.stat';

    private const CGROUP_V1_LIMIT = '/sys/fs/cgroup/memory/memory.limit_in_bytes';

    private const CGROUP_V1_USAGE = '/sys/fs/cgroup/memory/memory.usage_in_bytes';

    private const CGROUP_V1_STAT = '/sys/fs/cgroup/memory/memory.stat';

    private const MEMINFO = '/proc/meminfo';

    public function __construct(private readonly FileReader $files = new SystemFileReader())
    {
    }

    /** Null when nothing readable describes memory — macOS, Windows, or a locked-down open_basedir. */
    public function read(): ?MemoryUsage
    {
        return $this->fromCgroupV2()
            ?? $this->fromCgroupV1()
            ?? $this->fromMeminfo();
    }

    private function fromCgroupV2(): ?MemoryUsage
    {
        $limit = $this->limitBytes($this->files->read(self::CGROUP_V2_MAX));
        $current = $this->intFrom($this->files->read(self::CGROUP_V2_CURRENT));

        if ($limit === null || $current === null) {
            return null;
        }

        $inactiveFile = self::parseStatValue((string) $this->files->read(self::CGROUP_V2_STAT), 'inactive_file') ?? 0;

        return new MemoryUsage(
            max(0, $current - $inactiveFile),
            $limit,
            MemoryUsage::SOURCE_CGROUP_V2,
        );
    }

    private function fromCgroupV1(): ?MemoryUsage
    {
        $limit = $this->limitBytes($this->files->read(self::CGROUP_V1_LIMIT));
        $usage = $this->intFrom($this->files->read(self::CGROUP_V1_USAGE));

        if ($limit === null || $usage === null) {
            return null;
        }

        $inactiveFile = self::parseStatValue((string) $this->files->read(self::CGROUP_V1_STAT), 'total_inactive_file') ?? 0;

        return new MemoryUsage(
            max(0, $usage - $inactiveFile),
            $limit,
            MemoryUsage::SOURCE_CGROUP_V1,
        );
    }

    private function fromMeminfo(): ?MemoryUsage
    {
        $contents = $this->files->read(self::MEMINFO);

        if ($contents === null) {
            return null;
        }

        $total = self::parseMeminfoBytes($contents, 'MemTotal');

        if ($total === null || $total <= 0) {
            return null;
        }

        $available = self::parseMeminfoBytes($contents, 'MemAvailable');

        if ($available === null) {
            // Kernels before 3.14 have no MemAvailable. MemFree alone is not a
            // substitute — it excludes the page cache the kernel would hand
            // back on demand, so it reports a healthy server at 95% used.
            $available = (self::parseMeminfoBytes($contents, 'MemFree') ?? 0)
                + (self::parseMeminfoBytes($contents, 'Buffers') ?? 0)
                + (self::parseMeminfoBytes($contents, 'Cached') ?? 0)
                + (self::parseMeminfoBytes($contents, 'SReclaimable') ?? 0);
        }

        return new MemoryUsage(
            max(0, $total - min($total, $available)),
            $total,
            MemoryUsage::SOURCE_MEMINFO,
        );
    }

    /**
     * A cgroup limit in bytes, or null when the group is unconstrained.
     *
     * "Unconstrained" has two spellings: the literal `max` of cgroup v2, and
     * v1's sentinel of a near-PHP_INT_MAX byte count (commonly
     * 9223372036854771712). Both mean "the host's memory", and in that case
     * /proc/meminfo is the more accurate answer, so the caller falls through.
     */
    private function limitBytes(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);

        if ($raw === '' || $raw === 'max') {
            return null;
        }

        $limit = $this->intFrom($raw);

        if ($limit === null || $limit <= 0 || $limit >= (PHP_INT_MAX >> 1)) {
            return null;
        }

        return $limit;
    }

    private function intFrom(?string $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);

        return preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : null;
    }

    /**
     * Pull one `key value` pair out of a cgroup stat file. Exposed for tests.
     */
    public static function parseStatValue(string $contents, string $key): ?int
    {
        if (preg_match('/^'.preg_quote($key, '/').'\s+(\d+)$/m', $contents, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Pull one `Key:  1234 kB` line out of /proc/meminfo, as bytes. Exposed for tests.
     */
    public static function parseMeminfoBytes(string $contents, string $key): ?int
    {
        if (preg_match('/^'.preg_quote($key, '/').':\s+(\d+)\s*kB$/mi', $contents, $matches) === 1) {
            return (int) $matches[1] * 1024;
        }

        return null;
    }
}
