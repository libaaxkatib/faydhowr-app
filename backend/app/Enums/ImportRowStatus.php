<?php

namespace App\Enums;

enum ImportRowStatus: string
{
    // Staging classification (shown in the preview).
    /** Will be imported when the batch is confirmed. */
    case Valid = 'valid';
    /** Cannot be imported (missing/invalid data). Never imported. */
    case Invalid = 'invalid';
    /** Already exists (or repeated in the file). Skipped, never updated. */
    case Duplicate = 'duplicate';
    /** Uncertain match or conflict. Never imported automatically; a person must look at it. */
    case Review = 'review';

    // Outcome after confirmation.
    case Imported = 'imported';
    case Skipped = 'skipped';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
