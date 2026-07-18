<?php

namespace App\Enums\Auth;

enum OtpPurpose: string
{
    case Login = 'login';
    case PasswordReset = 'password_reset';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
