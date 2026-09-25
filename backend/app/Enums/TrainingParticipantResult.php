<?php

namespace App\Enums;

enum TrainingParticipantResult: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Absent = 'absent';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
