<?php

declare(strict_types=1);

namespace StatusHq\Support;

/**
 * A reader backed by an array of path => contents.
 *
 * Ships in src/ rather than tests/ on purpose: an application that wants to
 * assert on its own health endpoint needs to fake a container or a full disk,
 * and that is only possible if the fixture reader is part of the package.
 */
final class ArrayFileReader implements FileReader
{
    /**
     * @param  array<string, string>  $files
     */
    public function __construct(private array $files = [])
    {
    }

    public function read(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    public function with(string $path, string $contents): self
    {
        $clone = clone $this;
        $clone->files[$path] = $contents;

        return $clone;
    }

    public function without(string $path): self
    {
        $clone = clone $this;
        unset($clone->files[$path]);

        return $clone;
    }
}
