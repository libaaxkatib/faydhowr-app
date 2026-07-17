<?php

namespace App\Enums;

enum ReportFormat: string
{
    case Json = 'json';
    case Pdf = 'pdf';
    case Excel = 'excel';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isGeneratedInV1(): bool
    {
        return $this === self::Json;
    }
}
