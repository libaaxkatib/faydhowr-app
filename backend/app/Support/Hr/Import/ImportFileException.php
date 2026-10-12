<?php

namespace App\Support\Hr\Import;

use RuntimeException;

/** A whole-file problem (wrong type, too big, missing columns, ...) found before anything is staged. */
final class ImportFileException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
