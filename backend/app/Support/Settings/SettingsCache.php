<?php

namespace App\Support\Settings;

use App\Enums\Settings\SettingCategory;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache for the raw value maps of each settings category, shared by every
 * service that reads or invalidates settings.
 */
final class SettingsCache
{
    private const string PREFIX = 'settings.values.';

    /**
     * @param  Closure(): array<string, mixed>  $resolve
     * @return array<string, mixed> Map of key segment to raw value.
     */
    public function remember(SettingCategory $category, Closure $resolve): array
    {
        return Cache::rememberForever(self::PREFIX.$category->value, $resolve);
    }

    public function forget(SettingCategory $category): void
    {
        Cache::forget(self::PREFIX.$category->value);
    }

    public function forgetAll(): void
    {
        foreach (SettingCategory::cases() as $category) {
            $this->forget($category);
        }
    }
}
