<?php

namespace App\Support\Hr\Documents;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Raised when a document cannot be stored safely (storage unreachable, a write that was cut short, a read-back that does not
 * match). It carries a short reason code only: the underlying storage exception can contain file locations or bucket names,
 * so it is never attached and never logged. When it reaches the framework it answers 503 and reports a sanitized line.
 */
final class DocumentStorageException extends RuntimeException
{
    public function __construct(public readonly string $reason, private readonly ?string $causeClass = null)
    {
        parent::__construct('The document could not be stored.');
    }

    public static function from(string $reason, ?Throwable $cause = null): self
    {
        return new self($reason, $cause === null ? null : $cause::class);
    }

    public function report(): bool
    {
        Log::error('A private file (employee document or company agreement) could not be stored.', array_filter(['reason' => $this->reason, 'exception' => $this->causeClass]));

        return true; // handled: do not let the framework log the exception (and its trace) a second time
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error(
            'The document could not be saved because document storage is unavailable. Nothing was saved. Please try again in a few minutes.',
            'DOCUMENT_STORAGE_UNAVAILABLE',
            503,
        )->header('Retry-After', '60');
    }
}
