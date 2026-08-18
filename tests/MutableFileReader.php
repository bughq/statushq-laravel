<?php

declare(strict_types=1);

namespace StatusHq\Tests;

use StatusHq\Support\FileReader;

/**
 * A file reader whose contents can change between reads — which is the only
 * way to exercise counters that advance, or a cgroup that appears mid-run.
 */
final class MutableFileReader implements FileReader
{
    /**
     * @param  array<string, string>  $files
     */
    public function __construct(public array $files = [])
    {
    }

    public function read(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }
}
