<?php

namespace App\Exceptions\Upload;

use RuntimeException;

class InvalidPdfFileException extends RuntimeException
{
    public static function forFile(string $fileName): self
    {
        return new self("The PDF file [{$fileName}] is not a valid PDF document.");
    }
}
