<?php

declare(strict_types=1);

namespace StatusHq\Support;

/**
 * Reads the pseudo-files the kernel exposes host state through.
 *
 * This is an interface rather than direct file_get_contents() calls because
 * every one of those files (/proc/stat, /sys/fs/cgroup/*) exists only on
 * Linux. Injecting the reader is what lets the parsers be tested from captured
 * fixtures on a developer's macOS box, where none of these paths exist.
 */
interface FileReader
{
    /**
     * The file's contents, or null when it is absent or unreadable.
     *
     * Unreadable is not exceptional: open_basedir, a hardened container, or a
     * non-Linux host all make these paths simply not there, and the caller's
     * correct response is to report the metric as unavailable rather than to
     * fail.
     */
    public function read(string $path): ?string;
}
