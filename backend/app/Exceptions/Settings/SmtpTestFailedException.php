<?php

namespace App\Exceptions\Settings;

use RuntimeException;
use Throwable;

class SmtpTestFailedException extends RuntimeException
{
    public static function wrap(Throwable $previous): self
    {
        return new self('SMTP connection failed: '.$previous->getMessage(), previous: $previous);
    }

    public static function notConfigured(): self
    {
        return new self('SMTP is not configured: set smtp.host before sending a test email.');
    }
}
