<?php

namespace App\Support\Hr;

use App\Models\TemporaryReplacement;

/**
 * A token that changes whenever anything a person could be correcting on a Temporary cover changes. A screen sends it back with its change, so two people
 * working on the same cover at the same time can never silently overwrite each other (the later one is told to reload).
 */
final class ReplacementVersion
{
    public static function of(TemporaryReplacement $replacement): string
    {
        return sha1(json_encode([
            $replacement->start_date?->toDateString(),
            $replacement->end_date?->toDateString(),
            $replacement->planned_end_date?->toDateString(),
            $replacement->replacement_employee_id,
            $replacement->status?->value,
            $replacement->approved_at?->toIso8601String(),
            $replacement->reason,
            $replacement->notes,
        ], JSON_THROW_ON_ERROR));
    }
}
