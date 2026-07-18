<?php

namespace App\Exceptions\Upload;

use RuntimeException;

class UploadAttachedException extends RuntimeException
{
    public static function forUuid(string $uuid): self
    {
        return new self("Upload [{$uuid}] is attached and cannot be deleted.");
    }
}
