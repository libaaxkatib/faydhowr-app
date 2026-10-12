<?php

namespace App\Support\Hr\Import;

use App\Enums\ImportRowStatus;

/** What happened to one row when the batch was processed. Messages never contain personal data. */
final class RowOutcome
{
    private function __construct(
        public readonly ImportRowStatus $status,
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $entityType = null,
        public readonly ?int $entityId = null,
    ) {}

    public static function imported(string $entityType, int $entityId, string $message = 'Imported.'): self
    {
        return new self(ImportRowStatus::Imported, 'IMPORTED', $message, $entityType, $entityId);
    }

    /** A record that already exists (or changed since the preview) is left exactly as it is. */
    public static function skipped(string $code, string $message, ?string $entityType = null, ?int $entityId = null): self
    {
        return new self(ImportRowStatus::Skipped, $code, $message, $entityType, $entityId);
    }

    public static function failed(string $code, string $message): self
    {
        return new self(ImportRowStatus::Failed, $code, $message);
    }
}
