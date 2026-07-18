<?php

namespace App\Exceptions\Quotation;

use RuntimeException;

class QuotationInvalidStateException extends RuntimeException
{
    public static function forAction(string $message): self
    {
        return new self($message);
    }
}
