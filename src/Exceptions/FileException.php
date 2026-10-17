<?php

namespace Preuvia\Laravel\Exceptions;

/**
 * A local file cannot be read before an upload or written after a download.
 */
class FileException extends PreuviaException
{
    public static function unreadable(string $path): self
    {
        return new self("The file [{$path}] cannot be read before upload.");
    }

    public static function unwritable(string $path, ?string $disk = null): self
    {
        return new self($disk === null
            ? "Unable to write the Preuvia download to [{$path}]."
            : "Unable to store the Preuvia download on disk [{$disk}] at [{$path}].");
    }
}
