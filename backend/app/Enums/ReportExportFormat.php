<?php

namespace App\Enums;

enum ReportExportFormat: string
{
    case Pdf = 'pdf';
    case Xlsx = 'xlsx';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function fileExtension(): string
    {
        return $this->value;
    }
}
