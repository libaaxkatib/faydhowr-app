<?php

namespace App\Exceptions\Quotation;

use RuntimeException;

class QuotationAttachmentsLockedException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Attachments are immutable after submission. Additional files travel only through discussion.');
    }
}
