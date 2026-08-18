<?php

declare(strict_types=1);

namespace StatusHq\Support;

/**
 * The real thing: reads from the local filesystem.
 */
final class SystemFileReader implements FileReader
{
    public function read(string $path): ?string
    {
        // is_readable() first so that an open_basedir restriction raises a
        // warning here at most, not inside file_get_contents where a failure
        // would be indistinguishable from an empty file.
        if (! @is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }
}
