<?php

namespace App\Support\Hr\ExcelMigration;

use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeStatus;

/**
 * The outcome of resolving one Excel row's sheet + Stage cell against the
 * reviewed StageDictionary. When $needsManualReview is true, $status and
 * $pipelineStage are not to be trusted/written — the row is reported, not migrated.
 */
final readonly class StageResolution
{
    public function __construct(
        public ?EmployeeStatus $status,
        public ?EmployeePipelineStage $pipelineStage,
        public bool $isCancellation,
        public bool $needsManualReview,
        public ?string $reviewReason,
        public bool $wasDefaulted,
        public string $sourceNote,
    ) {}
}
