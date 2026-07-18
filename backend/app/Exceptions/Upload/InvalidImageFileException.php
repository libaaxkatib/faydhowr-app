<?php

namespace App\Exceptions\Upload;

use RuntimeException;

class InvalidImageFileException extends RuntimeException
{
    public static function forFile(string $fileName): self
    {
        return new self("The image file [{$fileName}] could not be decoded.");
    }
}
