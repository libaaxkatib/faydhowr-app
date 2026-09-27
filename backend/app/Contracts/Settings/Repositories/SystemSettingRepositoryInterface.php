<?php

namespace App\Contracts\Settings\Repositories;

use App\Enums\Settings\SettingCategory;
use App\Models\SystemSetting;
use Illuminate\Support\Collection;

interface SystemSettingRepositoryInterface
{
    /**
     * Every setting row, ordered by category then key.
     *
     * @return Collection<int, SystemSetting>
     */
    public function all(): Collection;

    /**
     * All settings of one category, keyed by key segment, with the
     * updating admin eager loaded.
     *
     * @return Collection<string, SystemSetting>
     */
    public function byCategory(SettingCategory $category): Collection;

    public function find(SettingCategory $category, string $key): ?SystemSetting;

    /**
     * The row for a registered key, creating it from its registry factory
     * default first if it does not exist yet (mirrors SystemSettingsSeeder).
     * Safe under concurrent calls for the same category/key: a unique-
     * constraint race is resolved by re-fetching the row the other request
     * just created rather than failing.
     */
    public function findOrCreate(SettingCategory $category, string $key, bool $isSensitive, mixed $default): SystemSetting;

    /**
     * Persist a new value. Sensitive settings are encrypted before saving.
     */
    public function setValue(SystemSetting $setting, mixed $value, int $adminId): SystemSetting;

    /**
     * Persist an already-stored representation verbatim (no encryption pass).
     * Used when restoring backup snapshots, whose sensitive values are
     * already ciphertext.
     */
    public function setStoredValue(SystemSetting $setting, mixed $storedValue, int $adminId): SystemSetting;
}
