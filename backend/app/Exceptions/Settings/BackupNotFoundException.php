<?php

namespace App\Exceptions\Settings;

use RuntimeException;

class BackupNotFoundException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Backup "%s" was not found.', $id));
    }

    public static function corrupt(string $id): self
    {
        return new self(sprintf('Backup "%s" is corrupt and cannot be restored.', $id));
    }
}
