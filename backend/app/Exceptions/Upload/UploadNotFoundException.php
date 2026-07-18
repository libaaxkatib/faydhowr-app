<?php

namespace App\Exceptions\Upload;

use RuntimeException;

class UploadNotFoundException extends RuntimeException
{
    public static function forUuid(string $uuid): self
    {
        return new self("Upload [{$uuid}] was not found.");
    }
}
