<?php

namespace App\DataTransferObjects\Settings;

use App\Contracts\Settings\SettingsCategoryValuesInterface;
use App\Enums\Settings\SettingCategory;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use JsonSerializable;

/**
 * One settings category with its typed values and change metadata.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class SettingsCategoryData implements Arrayable, JsonSerializable
{
    public function __construct(
        public SettingCategory $category,
        public SettingsCategoryValuesInterface $values,
        public ?string $lastUpdatedByName,
        public ?string $lastUpdatedByRole,
        public ?Carbon $updatedAt,
    ) {}

    /**
     * @return array{category: string, settings: array<string, mixed>, last_updated_by: array{name: string, role: string}|null, updated_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'settings' => $this->values->toArray(),
            'last_updated_by' => $this->lastUpdatedByName !== null
                ? ['name' => $this->lastUpdatedByName, 'role' => $this->lastUpdatedByRole ?? '']
                : null,
            'updated_at' => $this->updatedAt?->toIso8601String(),
        ];
    }

    /**
     * @return array{category: string, settings: array<string, mixed>, last_updated_by: array{name: string, role: string}|null, updated_at: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
