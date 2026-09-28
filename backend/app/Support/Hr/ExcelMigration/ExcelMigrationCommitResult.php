<?php

namespace App\Support\Hr\ExcelMigration;

/**
 * What ExcelMigrationCommitter actually wrote, for the command to report —
 * mirrors the dry-run report's own "never summarize away a discrepancy" rule.
 */
final readonly class ExcelMigrationCommitResult
{
    /**
     * @param  list<string>  $skippedDuplicatePhones  normalizedPhone of a ready-to-import
     *                                                person who already had a matching real Employee row
     *                                                (by phone) before this run — not re-created, not overwritten.
     */
    public function __construct(
        public int $employeesCreated,
        public int $separationsCreated,
        public int $statusHistoriesCreated,
        public int $skippedAsAlreadyImported,
        public array $skippedDuplicatePhones,
    ) {}
}
