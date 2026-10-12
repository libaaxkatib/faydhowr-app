<?php

namespace App\Enums;

enum ImportType: string
{
    case Companies = 'companies';
    case EmployeeRegistrations = 'employee_registrations';
    case WaitingList = 'waiting_list';
    case ActivationPreview = 'activation_preview';

    public function label(): string
    {
        return match ($this) {
            self::Companies => 'Companies',
            self::EmployeeRegistrations => 'New employee registrations',
            self::WaitingList => 'Waiting list',
            self::ActivationPreview => 'Activation preview (read-only)',
        };
    }

    /** A preview only reports; it can never be confirmed or change data. */
    public function isPreviewOnly(): bool
    {
        return $this === self::ActivationPreview;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
