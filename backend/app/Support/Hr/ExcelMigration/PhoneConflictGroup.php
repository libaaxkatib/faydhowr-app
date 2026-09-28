<?php

namespace App\Support\Hr\ExcelMigration;

/**
 * A normalized phone number shared by rows whose names are not compatible —
 * reported for manual review, never auto-merged.
 */
final readonly class PhoneConflictGroup
{
    /**
     * @param  list<array{sheet: string, row: int, name: string}>  $entries
     */
    public function __construct(
        public string $normalizedPhone,
        public array $entries,
    ) {}
}
