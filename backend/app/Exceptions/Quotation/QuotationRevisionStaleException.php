<?php

namespace App\Exceptions\Quotation;

use RuntimeException;

class QuotationRevisionStaleException extends RuntimeException
{
    public static function make(): self
    {
        return new self('A newer revision exists. Refetch the quotation and confirm the latest version.');
    }
}
