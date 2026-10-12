<?php

namespace App\Support\Hr\Import;

use RuntimeException;

/** A refused import operation (wrong state, repeat upload, busy batch, ...) with an API error code and status. */
final class ImportDomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
