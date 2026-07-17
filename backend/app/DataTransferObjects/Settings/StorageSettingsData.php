<?php

namespace App\DataTransferObjects\Settings;

use App\Contracts\Settings\SettingsCategoryValuesInterface;

final readonly class StorageSettingsData implements SettingsCategoryValuesInterface
{
    /**
     * @param  list<string>|null  $allowedFileTypes
     */
    public function __construct(
        public ?string $driver,
        public ?int $maxUploadSize,
        public ?array $allowedFileTypes,
    ) {}

    public static function fromValues(array $values): static
    {
        return new self(
            driver: $values['driver'] ?? null,
            maxUploadSize: isset($values['max_upload_size']) ? (int) $values['max_upload_size'] : null,
            allowedFileTypes: $values['allowed_file_types'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'storage.driver' => $this->driver,
            'storage.max_upload_size' => $this->maxUploadSize,
            'storage.allowed_file_types' => $this->allowedFileTypes,
        ];
    }
}
