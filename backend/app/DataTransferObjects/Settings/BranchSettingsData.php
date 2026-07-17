<?php

namespace App\DataTransferObjects\Settings;

use App\Contracts\Settings\SettingsCategoryValuesInterface;

/**
 * The branch settings category holds only the default-branch pointer; the
 * relational `branches` table is authoritative for branch records.
 */
final readonly class BranchSettingsData implements SettingsCategoryValuesInterface
{
    public function __construct(
        public ?string $default,
    ) {}

    public static function fromValues(array $values): static
    {
        return new self(
            default: $values['default'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'branch.default' => $this->default,
        ];
    }
}
