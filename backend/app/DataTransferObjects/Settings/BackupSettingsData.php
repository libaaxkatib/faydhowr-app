<?php

namespace App\DataTransferObjects\Settings;

use App\Contracts\Settings\SettingsCategoryValuesInterface;

final readonly class BackupSettingsData implements SettingsCategoryValuesInterface
{
    public function __construct(
        public ?bool $enabled,
        public ?int $retentionDays,
        public ?string $lastRunAt,
    ) {}

    public static function fromValues(array $values): static
    {
        return new self(
            enabled: isset($values['enabled']) ? (bool) $values['enabled'] : null,
            retentionDays: isset($values['retention_days']) ? (int) $values['retention_days'] : null,
            lastRunAt: $values['last_run_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'backup.enabled' => $this->enabled,
            'backup.retention_days' => $this->retentionDays,
            'backup.last_run_at' => $this->lastRunAt,
        ];
    }
}
