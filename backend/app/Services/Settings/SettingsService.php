<?php

namespace App\Services\Settings;

use App\Contracts\Settings\Repositories\SystemSettingRepositoryInterface;
use App\Contracts\Settings\Services\AuditServiceInterface;
use App\Contracts\Settings\Services\SettingsServiceInterface;
use App\Contracts\Settings\SettingsCategoryValuesInterface;
use App\DataTransferObjects\Settings\BackupSettingsData;
use App\DataTransferObjects\Settings\BranchSettingsData;
use App\DataTransferObjects\Settings\CompanySettingsData;
use App\DataTransferObjects\Settings\CurrencySettingsData;
use App\DataTransferObjects\Settings\LocalizationSettingsData;
use App\DataTransferObjects\Settings\NotificationsSettingsData;
use App\DataTransferObjects\Settings\NumberingSettingsData;
use App\DataTransferObjects\Settings\SettingsCategoryData;
use App\DataTransferObjects\Settings\SmtpSettingsData;
use App\DataTransferObjects\Settings\StorageSettingsData;
use App\DataTransferObjects\Settings\TaxSettingsData;
use App\Enums\Settings\SettingCategory;
use App\Exceptions\Settings\SmtpTestFailedException;
use App\Models\Admin;
use App\Models\SystemSetting;
use App\Support\Settings\SettingsCache;
use App\Support\Settings\SettingsRegistry;
use App\Support\Settings\SettingValueEncrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SettingsService implements SettingsServiceInterface
{
    public function __construct(
        private SystemSettingRepositoryInterface $settings,
        private AuditServiceInterface $audit,
        private SettingsCache $cache,
        private SettingValueEncrypter $encrypter,
    ) {}

    public function allSettings(): array
    {
        return array_map(
            fn (SettingCategory $category): SettingsCategoryData => $this->categorySettings($category),
            SettingCategory::cases(),
        );
    }

    public function categorySettings(SettingCategory $category): SettingsCategoryData
    {
        $settings = $this->settings->byCategory($category);

        $values = $settings->map(fn (SystemSetting $setting): mixed => $setting->value)->all();

        /** @var SystemSetting|null $lastUpdated */
        $lastUpdated = $settings
            ->filter(fn (SystemSetting $setting): bool => $setting->updated_by !== null)
            ->sortByDesc('updated_at')
            ->first();

        return new SettingsCategoryData(
            category: $category,
            values: $this->hydrateValues($category, $values),
            lastUpdatedByName: $lastUpdated?->updatedBy?->full_name,
            lastUpdatedByRole: $lastUpdated?->updatedBy?->role->value,
            updatedAt: $lastUpdated?->updated_at,
        );
    }

    public function updateCategory(SettingCategory $category, array $values, Admin $admin, ?string $ipAddress): SettingsCategoryData
    {
        DB::transaction(function () use ($category, $values, $admin, $ipAddress): void {
            $settings = $this->settings->byCategory($category);

            foreach ($values as $qualifiedKey => $newValue) {
                $key = $this->keySegment($qualifiedKey);
                /** @var SystemSetting|null $setting */
                $setting = $settings->get($key);

                if ($setting === null) {
                    continue;
                }

                $currentValue = $setting->is_sensitive
                    ? $this->encrypter->decrypt($setting->value)
                    : $setting->value;

                if ($currentValue === $newValue) {
                    continue;
                }

                $oldValue = $setting->value;
                $this->settings->setValue($setting, $newValue, $admin->id);

                $this->audit->record(
                    category: $category->value,
                    key: $key,
                    oldValue: $oldValue,
                    newValue: $newValue,
                    admin: $admin,
                    ipAddress: $ipAddress,
                    sensitive: $setting->is_sensitive,
                );
            }
        });

        $this->cache->forget($category);

        return $this->categorySettings($category);
    }

    public function restoreDefaults(SettingCategory $category, Admin $admin, ?string $ipAddress): SettingsCategoryData
    {
        DB::transaction(function () use ($category, $admin, $ipAddress): void {
            foreach ($this->settings->byCategory($category) as $setting) {
                if ($setting->value === $setting->default_value) {
                    continue;
                }

                $oldValue = $setting->value;
                $this->settings->setValue($setting, $setting->default_value, $admin->id);

                $this->audit->record(
                    category: $category->value,
                    key: $setting->key,
                    oldValue: $oldValue,
                    newValue: $setting->default_value,
                    admin: $admin,
                    ipAddress: $ipAddress,
                    sensitive: $setting->is_sensitive,
                );
            }
        });

        $this->cache->forget($category);

        return $this->categorySettings($category);
    }

    public function value(string $qualifiedKey): mixed
    {
        [$categoryValue, $key] = explode('.', $qualifiedKey, 2);
        $category = SettingCategory::from($categoryValue);

        $values = $this->cache->remember(
            $category,
            fn (): array => $this->settings->byCategory($category)
                ->map(fn (SystemSetting $setting): mixed => $setting->value)
                ->all(),
        );

        $value = $values[$key] ?? null;

        return SettingsRegistry::isSensitive($category, $key)
            ? $this->encrypter->decrypt($value)
            : $value;
    }

    public function storeCompanyLogo(UploadedFile $file, Admin $admin, ?string $ipAddress): string
    {
        $path = $file->store('settings', 'public');
        $url = Storage::disk('public')->url($path);

        $this->updateCategory(SettingCategory::Company, ['company.logo' => $url], $admin, $ipAddress);

        return $url;
    }

    public function sendTestEmail(string $toEmail): void
    {
        $host = $this->value('smtp.host');

        if ($host === null || $host === '') {
            throw SmtpTestFailedException::notConfigured();
        }

        $encryption = $this->value('smtp.encryption');

        try {
            Mail::build([
                'transport' => 'smtp',
                'host' => $host,
                'port' => (int) ($this->value('smtp.port') ?? 587),
                'encryption' => $encryption === 'none' ? null : $encryption,
                'username' => $this->value('smtp.username'),
                'password' => $this->value('smtp.password'),
                'timeout' => 15,
            ])->raw(
                'This is a test email from Fayadhowr to verify the SMTP configuration.',
                fn ($message) => $message->to($toEmail)->subject('Fayadhowr SMTP Test'),
            );
        } catch (Throwable $exception) {
            throw SmtpTestFailedException::wrap($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $values  Map of key segment to raw value.
     */
    private function hydrateValues(SettingCategory $category, array $values): SettingsCategoryValuesInterface
    {
        return match ($category) {
            SettingCategory::Company => CompanySettingsData::fromValues($values),
            SettingCategory::Branch => BranchSettingsData::fromValues($values),
            SettingCategory::Currency => CurrencySettingsData::fromValues($values),
            SettingCategory::Tax => TaxSettingsData::fromValues($values),
            SettingCategory::Numbering => NumberingSettingsData::fromValues($values),
            SettingCategory::Smtp => SmtpSettingsData::fromValues($values),
            SettingCategory::Notifications => NotificationsSettingsData::fromValues($values),
            SettingCategory::Storage => StorageSettingsData::fromValues($values),
            SettingCategory::Localization => LocalizationSettingsData::fromValues($values),
            SettingCategory::Backup => BackupSettingsData::fromValues($values),
        };
    }

    private function keySegment(string $qualifiedKey): string
    {
        return str_contains($qualifiedKey, '.')
            ? explode('.', $qualifiedKey, 2)[1]
            : $qualifiedKey;
    }
}
