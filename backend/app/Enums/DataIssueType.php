<?php

namespace App\Enums;

enum DataIssueType: string
{
    case MissingData = 'missing_data';
    case MissingRecord = 'missing_record';
    case Duplicate = 'duplicate';
    case Conflict = 'conflict';
    case SourceMismatch = 'source_mismatch';
    case CountDiscrepancy = 'count_discrepancy';
    case InvalidRecord = 'invalid_record';
    case SystemError = 'system_error';
    case MigrationIssue = 'migration_issue';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::MissingData => 'Missing Data',
            self::MissingRecord => 'Missing Record',
            self::Duplicate => 'Duplicate',
            self::Conflict => 'Conflict',
            self::SourceMismatch => 'Source Mismatch',
            self::CountDiscrepancy => 'Count Discrepancy',
            self::InvalidRecord => 'Invalid Record',
            self::SystemError => 'System Error',
            self::MigrationIssue => 'Migration Issue',
            self::Other => 'Other',
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
