<?php

namespace App\DataTransferObjects\Settings;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use JsonSerializable;

/**
 * Metadata for one settings backup archive stored on disk.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class BackupData implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $id,
        public int $sizeBytes,
        public ?string $createdBy,
        public Carbon $createdAt,
    ) {}

    /**
     * @return array{id: string, size_bytes: int, created_by: string|null, created_at: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'size_bytes' => $this->sizeBytes,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt->toIso8601String(),
        ];
    }

    /**
     * @return array{id: string, size_bytes: int, created_by: string|null, created_at: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
