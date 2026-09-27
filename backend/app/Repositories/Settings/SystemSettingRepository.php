<?php

namespace App\Repositories\Settings;

use App\Contracts\Settings\Repositories\SystemSettingRepositoryInterface;
use App\Enums\Settings\SettingCategory;
use App\Models\SystemSetting;
use App\Support\Settings\SettingValueEncrypter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class SystemSettingRepository implements SystemSettingRepositoryInterface
{
    public function __construct(private SettingValueEncrypter $encrypter) {}

    public function all(): Collection
    {
        return SystemSetting::query()
            ->orderBy('category')
            ->orderBy('key')
            ->get();
    }

    public function byCategory(SettingCategory $category): Collection
    {
        return SystemSetting::query()
            ->category($category->value)
            ->with('updatedBy')
            ->orderBy('key')
            ->get()
            ->keyBy('key');
    }

    public function find(SettingCategory $category, string $key): ?SystemSetting
    {
        return SystemSetting::query()
            ->category($category->value)
            ->where('key', $key)
            ->first();
    }

    public function findOrCreate(SettingCategory $category, string $key, bool $isSensitive, mixed $default): SystemSetting
    {
        try {
            return SystemSetting::query()->firstOrCreate(
                ['category' => $category->value, 'key' => $key],
                ['value' => $default, 'default_value' => $default, 'is_sensitive' => $isSensitive],
            );
        } catch (QueryException $exception) {
            // Unique constraint race: a concurrent request created this row first.
            return $this->find($category, $key) ?? throw $exception;
        }
    }

    public function setValue(SystemSetting $setting, mixed $value, int $adminId): SystemSetting
    {
        return $this->setStoredValue(
            $setting,
            $setting->is_sensitive ? $this->encrypter->encrypt($value) : $value,
            $adminId,
        );
    }

    public function setStoredValue(SystemSetting $setting, mixed $storedValue, int $adminId): SystemSetting
    {
        $setting->forceFill([
            'value' => $storedValue,
            'updated_by' => $adminId,
        ])->save();

        return $setting;
    }
}
