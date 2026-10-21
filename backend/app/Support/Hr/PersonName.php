<?php

namespace App\Support\Hr;

/**
 * A person's name as it is stored: surrounding and repeated spaces are removed ("  Amina   Ali " -> "Amina Ali"), nothing else is changed
 * (letter case and spelling stay exactly as typed, and two different people may legitimately share a name).
 */
final class PersonName
{
    public static function normalise(?string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
    }
}
