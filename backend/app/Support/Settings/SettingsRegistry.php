<?php

namespace App\Support\Settings;

use App\Enums\Settings\SettingCategory;

/**
 * Single source of truth for the approved settings catalog: every dotted
 * `category.key`, its factory default, whether it is sensitive (masked and
 * never echoed by read APIs) and whether admins may edit it through the API.
 */
final class SettingsRegistry
{
    private const string SENSITIVE_MASK = '********';

    /**
     * @return array<string, array<string, array{default: mixed, sensitive: bool, editable: bool}>>
     *                                                                                              Map of category value to key segment to definition.
     */
    public static function definitions(): array
    {
        return [
            SettingCategory::Company->value => [
                'name' => ['default' => 'Fayadhowr', 'sensitive' => false, 'editable' => true],
                'logo' => ['default' => null, 'sensitive' => false, 'editable' => true],
                'email' => ['default' => null, 'sensitive' => false, 'editable' => true],
                'phone' => ['default' => null, 'sensitive' => false, 'editable' => true],
                'website' => ['default' => null, 'sensitive' => false, 'editable' => true],
                'address' => ['default' => null, 'sensitive' => false, 'editable' => true],
                'tax_id' => ['default' => null, 'sensitive' => false, 'editable' => true],
                'business_hours_open' => ['default' => '08:00', 'sensitive' => false, 'editable' => true],
                'business_hours_close' => ['default' => '18:00', 'sensitive' => false, 'editable' => true],
                'facebook' => ['default' => null, 'sensitive' => false, 'editable' => true],
                'instagram' => ['default' => null, 'sensitive' => false, 'editable' => true],
                'whatsapp' => ['default' => null, 'sensitive' => false, 'editable' => true],
            ],
            SettingCategory::Branch->value => [
                'default' => ['default' => 'MGQ', 'sensitive' => false, 'editable' => false],
            ],
            SettingCategory::Currency->value => [
                'default' => ['default' => 'USD', 'sensitive' => false, 'editable' => true],
                'symbol' => ['default' => '$', 'sensitive' => false, 'editable' => true],
                'decimal_places' => ['default' => 2, 'sensitive' => false, 'editable' => true],
                'thousand_separator' => ['default' => ',', 'sensitive' => false, 'editable' => true],
            ],
            SettingCategory::Tax->value => [
                'default' => ['default' => false, 'sensitive' => false, 'editable' => true],
                'rate' => ['default' => 0, 'sensitive' => false, 'editable' => true],
                'mode' => ['default' => 'exclusive', 'sensitive' => false, 'editable' => true],
            ],
            SettingCategory::Numbering->value => [
                'customer_prefix' => ['default' => 'CUS', 'sensitive' => false, 'editable' => true],
                'booking_prefix' => ['default' => 'BKG', 'sensitive' => false, 'editable' => true],
                'quotation_prefix' => ['default' => 'QTN', 'sensitive' => false, 'editable' => true],
                'invoice_prefix' => ['default' => 'INV', 'sensitive' => false, 'editable' => true],
                'receipt_prefix' => ['default' => 'RCT', 'sensitive' => false, 'editable' => true],
                'order_prefix' => ['default' => 'ORD', 'sensitive' => false, 'editable' => true],
                'payment_prefix' => ['default' => 'PAY', 'sensitive' => false, 'editable' => true],
                'auto_numbering' => ['default' => true, 'sensitive' => false, 'editable' => true],
            ],
            SettingCategory::Smtp->value => [
                'host' => ['default' => null, 'sensitive' => false, 'editable' => true],
                'port' => ['default' => 587, 'sensitive' => false, 'editable' => true],
                'encryption' => ['default' => 'tls', 'sensitive' => false, 'editable' => true],
                'username' => ['default' => null, 'sensitive' => false, 'editable' => true],
                'password' => ['default' => null, 'sensitive' => true, 'editable' => true],
            ],
            SettingCategory::Notifications->value => [
                'email' => ['default' => true, 'sensitive' => false, 'editable' => true],
                'browser' => ['default' => true, 'sensitive' => false, 'editable' => true],
                'booking_alerts' => ['default' => true, 'sensitive' => false, 'editable' => true],
                'quotation_alerts' => ['default' => true, 'sensitive' => false, 'editable' => true],
                'payment_alerts' => ['default' => true, 'sensitive' => false, 'editable' => true],
            ],
            SettingCategory::Storage->value => [
                'driver' => ['default' => 'local', 'sensitive' => false, 'editable' => true],
                'max_upload_size' => ['default' => 10240, 'sensitive' => false, 'editable' => true],
                'allowed_file_types' => ['default' => ['jpg', 'jpeg', 'png', 'pdf'], 'sensitive' => false, 'editable' => true],
            ],
            SettingCategory::Localization->value => [
                'language' => ['default' => 'en', 'sensitive' => false, 'editable' => true],
                'timezone' => ['default' => 'Africa/Mogadishu', 'sensitive' => false, 'editable' => true],
                'date_format' => ['default' => 'DD/MM/YYYY', 'sensitive' => false, 'editable' => true],
                'time_format' => ['default' => 'hh:mm A', 'sensitive' => false, 'editable' => true],
            ],
            SettingCategory::Backup->value => [
                'enabled' => ['default' => false, 'sensitive' => false, 'editable' => true],
                'retention_days' => ['default' => 30, 'sensitive' => false, 'editable' => true],
                'last_run_at' => ['default' => null, 'sensitive' => false, 'editable' => false],
            ],
        ];
    }

    /**
     * @return array<string, array{default: mixed, sensitive: bool, editable: bool}>
     *                                                                               Map of key segment to definition for one category.
     */
    public static function definitionsFor(SettingCategory $category): array
    {
        return self::definitions()[$category->value];
    }

    /**
     * @return list<string> Key segments admins may edit through the settings API.
     */
    public static function editableKeysFor(SettingCategory $category): array
    {
        return array_keys(array_filter(
            self::definitionsFor($category),
            fn (array $definition): bool => $definition['editable'],
        ));
    }

    public static function isSensitive(SettingCategory $category, string $key): bool
    {
        return self::definitionsFor($category)[$key]['sensitive'] ?? false;
    }

    public static function mask(): string
    {
        return self::SENSITIVE_MASK;
    }
}
