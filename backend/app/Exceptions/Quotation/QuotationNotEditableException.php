<?php

namespace App\Exceptions\Quotation;

use RuntimeException;

class QuotationNotEditableException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Only draft quotations can be edited.');
    }
}
