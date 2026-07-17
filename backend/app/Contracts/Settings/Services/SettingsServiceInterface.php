<?php

namespace App\Contracts\Settings\Services;

use App\DataTransferObjects\Settings\SettingsCategoryData;
use App\Enums\Settings\SettingCategory;
use App\Exceptions\Settings\SmtpTestFailedException;
use App\Models\Admin;
use Illuminate\Http\UploadedFile;

interface SettingsServiceInterface
{
    /**
     * All categories in canonical order.
     *
     * @return list<SettingsCategoryData>
     */
    public function allSettings(): array;

    public function categorySettings(SettingCategory $category): SettingsCategoryData;

    /**
     * Apply a partial update to one category. Every changed key is
     * audit-logged (sensitive values masked) and the category cache is
     * invalidated.
     *
     * @param  array<string, mixed>  $values  Map of fully-qualified dotted key to new value.
     */
    public function updateCategory(SettingCategory $category, array $values, Admin $admin, ?string $ipAddress): SettingsCategoryData;

    /**
     * Reset every value of the category back to its default_value.
     */
    public function restoreDefaults(SettingCategory $category, Admin $admin, ?string $ipAddress): SettingsCategoryData;

    /**
     * Raw value of one setting addressed by its dotted key, e.g. "smtp.host".
     */
    public function value(string $qualifiedKey): mixed;

    /**
     * Store the uploaded company logo and update company.logo.
     *
     * @return string Publicly accessible logo URL.
     */
    public function storeCompanyLogo(UploadedFile $file, Admin $admin, ?string $ipAddress): string;

    /**
     * Send a test email through the saved SMTP configuration.
     *
     * @throws SmtpTestFailedException
     */
    public function sendTestEmail(string $toEmail): void;
}
