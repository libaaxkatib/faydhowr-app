<?php

namespace App\Enums\Settings;

enum SettingCategory: string
{
    case Company = 'company';
    case Branch = 'branch';
    case Currency = 'currency';
    case Tax = 'tax';
    case Numbering = 'numbering';
    case Smtp = 'smtp';
    case Notifications = 'notifications';
    case Storage = 'storage';
    case Localization = 'localization';
    case Backup = 'backup';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Company',
            self::Branch => 'Branches',
            self::Currency => 'Currency',
            self::Tax => 'Tax',
            self::Numbering => 'Numbering',
            self::Smtp => 'SMTP',
            self::Notifications => 'Notifications',
            self::Storage => 'Storage',
            self::Localization => 'Localization',
            self::Backup => 'Backup',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
