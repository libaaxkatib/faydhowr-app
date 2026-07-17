<?php

namespace App\Support\Settings;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypts sensitive setting values before they are persisted and decrypts
 * them for internal use. Decryption is tolerant of legacy plain-text values:
 * anything that cannot be decrypted is returned as-is, so pre-encryption
 * rows keep working and are re-encrypted on their next write.
 */
final class SettingValueEncrypter
{
    public function encrypt(mixed $value): ?string
    {
        return $value === null ? null : Crypt::encrypt($value);
    }

    public function decrypt(mixed $stored): mixed
    {
        if (! is_string($stored) || $stored === '') {
            return $stored;
        }

        try {
            return Crypt::decrypt($stored);
        } catch (DecryptException) {
            return $stored;
        }
    }
}
