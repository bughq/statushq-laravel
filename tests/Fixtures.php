<?php

declare(strict_types=1);

namespace StatusHq\Tests;

/**
 * Captured Linux pseudo-file contents.
 *
 * These are fixtures rather than live reads because the package is developed
 * on macOS, where none of these paths exist — and because the cases that
 * matter (a container at its memory limit, a counter reset by a reboot) are
 * not reproducible on demand anywhere.
 */
final class Fixtures
{
    /** A 64 GB host with ~19 GB genuinely available. */
    public const MEMINFO = <<<'TXT'
        MemTotal:       65798616 kB
        MemFree:         2338712 kB
        MemAvailable:   19603868 kB
        Buffers:          856140 kB
        Cached:         18899828 kB
        SwapCached:            0 kB
        Active:         38209012 kB
        Inactive:       19452928 kB
        SReclaimable:    1204832 kB
        TXT;

    /** The same host on a kernel too old to publish MemAvailable. */
    public const MEMINFO_WITHOUT_AVAILABLE = <<<'TXT'
        MemTotal:       65798616 kB
        MemFree:         2338712 kB
        Buffers:          856140 kB
        Cached:         18899828 kB
        SReclaimable:    1204832 kB
        TXT;

    /** cgroup v2 memory.stat, trimmed to the keys that are read. */
    public const CGROUP_V2_MEMORY_STAT = <<<'TXT'
        anon 268435456
        file 134217728
        kernel_stack 1048576
        slab 12582912
        inactive_anon 4194304
        active_anon 264241152
        inactive_file 100663296
        active_file 33554432
        TXT;

    public const CGROUP_V1_MEMORY_STAT = <<<'TXT'
        cache 134217728
        rss 268435456
        total_cache 134217728
        total_rss 268435456
        total_inactive_file 100663296
        total_active_file 33554432
        TXT;

    public const CGROUP_V2_CPU_STAT = <<<'TXT'
        usage_usec 4200000
        user_usec 3100000
        system_usec 1100000
        nr_periods 0
        nr_throttled 0
        throttled_usec 0
        TXT;

    /** 8 CPUs. The `cpu` aggregate line is the only one read. */
    public const PROC_STAT = <<<'TXT'
        cpu  120000 3000 40000 900000 8000 0 2000 0 500 100
        cpu0 15000 400 5000 112000 1000 0 250 0 60 12
        cpu1 15100 380 5100 112500 990 0 260 0 62 13
        intr 123456789
        ctxt 987654321
        btime 1755500000
        TXT;
}
