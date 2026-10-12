<?php

namespace App\Support\Hr\Import;

use App\Enums\ImportRowStatus;

/**
 * The staging verdict for one workbook row. `payload`, `nameKey`, `phoneKey` and `meta` may hold personal
 * data and are removed by the 30-day retention purge; `code` and `message` must never contain a name or a
 * phone number because they are kept as the audit trail.
 */
final class StagedRow
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $meta
     */
    private function __construct(
        public readonly ImportRowStatus $status,
        public readonly ?string $code,
        public readonly string $message,
        public readonly ?array $payload,
        public readonly ?string $nameKey,
        public readonly ?string $phoneKey,
        public readonly ?array $meta,
        public readonly ?string $entityType,
        public readonly ?int $entityId,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $meta
     */
    public static function valid(array $payload, ?string $nameKey = null, ?string $phoneKey = null, ?array $meta = null, ?string $entityType = null, ?int $entityId = null, string $message = 'Ready to import.'): self
    {
        return new self(ImportRowStatus::Valid, null, $message, $payload, $nameKey, $phoneKey, $meta, $entityType, $entityId);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $meta
     */
    public static function invalid(string $code, string $message, ?array $payload = null, ?string $nameKey = null, ?string $phoneKey = null, ?array $meta = null): self
    {
        return new self(ImportRowStatus::Invalid, $code, $message, $payload, $nameKey, $phoneKey, $meta, null, null);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $meta
     */
    public static function duplicate(string $code, string $message, ?array $payload = null, ?string $nameKey = null, ?string $phoneKey = null, ?array $meta = null, ?string $entityType = null, ?int $entityId = null): self
    {
        return new self(ImportRowStatus::Duplicate, $code, $message, $payload, $nameKey, $phoneKey, $meta, $entityType, $entityId);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $meta
     */
    public static function review(string $code, string $message, ?array $payload = null, ?string $nameKey = null, ?string $phoneKey = null, ?array $meta = null): self
    {
        return new self(ImportRowStatus::Review, $code, $message, $payload, $nameKey, $phoneKey, $meta, null, null);
    }

    /** The same verdict re-labelled as a repeat inside the file (second and later occurrences). */
    public function asRepeatInFile(string $what): self
    {
        return new self(ImportRowStatus::Duplicate, 'DUPLICATE_IN_FILE', "This {$what} already appears earlier in the file; only the first one is used.", $this->payload, $this->nameKey, $this->phoneKey, $this->meta, null, null);
    }
}
