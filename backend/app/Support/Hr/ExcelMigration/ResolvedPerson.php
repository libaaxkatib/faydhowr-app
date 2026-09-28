<?php

namespace App\Support\Hr\ExcelMigration;

use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeStatus;
use Carbon\Carbon;

/**
 * One deduplicated person, merged from every Excel row that shares their
 * normalized phone number (or a single unmatched row). Represents what a
 * future --commit run would write, without writing anything.
 */
final readonly class ResolvedPerson
{
    /**
     * @param  list<string>  $sourceSheets
     * @param  list<string>  $sourceRefs  "SheetName:row" references
     * @param  list<string>  $cancellationSources  e.g. "RED fill in REGISTRATION (row 5)", "CANCELED sheet (row 12)" — empty unless $isCancellation
     * @param  list<string>  $reviewReasons  empty when $isReadyToImport is true
     * @param  list<string>  $profileIncompleteReasons  never empty — every Excel-migrated person has an incomplete HR profile by definition; this is NOT a review blocker (see $reviewReasons)
     */
    public function __construct(
        public string $normalizedPhone,
        public ?string $alternatePhone,
        public string $fullName,
        public ?string $location,
        public ?int $age,
        public ?string $maritalStatus,
        public ?string $livesWith,
        public ?string $source,
        public ?string $experience,
        public ?string $rawJobTitle,
        public ?string $matchedCategory,
        /**
         * False when $matchedCategory came from the General Cleaning catch-all fallback
         * (Job text didn't match any real keyword) rather than a genuine keyword match —
         * always true for a confident match, including the "Supervisor" and Home
         * Cleaning/Cooking keyword rules. $rawJobTitle always preserves the original text
         * either way.
         */
        public bool $matchedCategoryIsConfident,
        /**
         * CONFIRMED business structure: a category-specific sub-classification —
         * for "Home Team" this is the Work Type (HomeCleaningWorkTypeMatcher::
         * FULL_TIME_JIIF / PART_TIME_MAALIN), for "Cooking" this is the
         * Specialization (CookingSpecializationMatcher::COOK / CUNTO_AND_NADAAFAD).
         * Null for every other category, and null when the source text doesn't
         * clearly indicate one — never guessed. See ExcelMigrationService::resolveGroup.
         */
        public ?string $categorySpecialization,
        public bool $isSupervisor,
        public ?EmployeeStatus $status,
        public ?EmployeePipelineStage $pipelineStage,
        public bool $isCancellation,
        public array $cancellationSources,
        /** A genuine, verbatim cancellation reason — null unless one actually exists in the source (never guessed from Stage/fee-status noise). See CancellationReasonExtractor. */
        public ?string $cancellationReason,
        /** Full raw, labeled Stage/Other-Info/reason-column text for every cancellation-source row — traceability only, NOT asserted as "the reason". */
        public ?string $cancellationSourceContext,
        /** REGISTRATION's own date — the person's original registration date, never a substitute. */
        public ?Carbon $applicationDate,
        /**
         * True only when $applicationDate came from RegistrationDateInferrer (the row's
         * own Date cell could not be parsed, even after DateParser's format extensions,
         * but the nearest valid date immediately before and after this row in
         * REGISTRATION agreed exactly). Never true for a directly-sourced date.
         */
        public bool $applicationDateInferred,
        /** Audit trail when $applicationDateInferred is true — which rows/dates were used as evidence and why. Null otherwise. */
        public ?string $applicationDateInferenceNote,
        /** A Waiting-List-sheet's own date, kept separate from $applicationDate — never conflated. */
        public ?Carbon $waitingSince,
        public ?string $trainingFeeStatus,
        public ?string $trainingFeeRawUnmapped,
        /**
         * CONFIRMED: "Other Contact"/"Other Contact Name" are the EMPLOYEE's own
         * secondary/emergency contact (a second number to reach them by if their
         * primary phone is unreachable) — NOT the guarantor/Damiin, and never used
         * to populate one. Excel contains no guarantor data at all; the actual
         * guarantor is added later in the Web Panel (Employee Profile → Guarantor/
         * Damiin → Add Guarantor). Preserved here only when reliable, purely as
         * employee contact info.
         */
        public ?string $secondaryContactPhone,
        public ?string $secondaryContactName,
        public ?string $otherInfo,
        public string $historyNote,
        /** REGISTRATION row highlight — see RegistrationColorReader. Confirmed: person already brought a guarantor. */
        public bool $isYellowFlagged,
        /** REGISTRATION row highlight — confirmed: currently works, or was previously sent/assigned to work. */
        public bool $isGreenFlagged,
        /**
         * Independent derived business flag — "does this person currently need a
         * guarantor". Deliberately NOT a pipelineStage (see StageDictionary):
         * never competes by rank, never disappears because another stage exists.
         * Source signal = REGISTRATION "other color"/"no fill" OR NEED DAMIIN
         * sheet membership; overridden to false by green, yellow (already
         * provided), or Waiting status — see ExcelMigrationService::resolveGroup.
         */
        public bool $guarantorNeedFlag,
        public array $sourceSheets,
        public array $sourceRefs,
        public bool $isReadyToImport,
        public array $reviewReasons,
        public array $profileIncompleteReasons,
    ) {}
}
