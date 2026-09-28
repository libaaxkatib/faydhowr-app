<?php

namespace App\Support\Hr\ExcelMigration;

/**
 * Aggregated dry-run output. Populated by ExcelMigrationService; never
 * writes anything itself. `toArray()` is what the command prints and what
 * tests assert against.
 */
final class ExcelMigrationReport
{
    public int $totalRowsScanned = 0;

    public int $realPersonRows = 0;

    public int $missingNameCount = 0;

    public int $missingPhoneCount = 0;

    /** @var list<ResolvedPerson> */
    public array $readyToImport = [];

    /** @var list<ResolvedPerson> */
    public array $manualReview = [];

    /** @var list<PhoneConflictGroup> */
    public array $phoneConflicts = [];

    /** Count of Waiting-family rows with no valid phone whose identity was safely recovered via a conservative REGISTRATION name match (never a duplicate Employee). */
    public int $waitingPhoneRecoveredViaNameMatchCount = 0;

    /** @var list<array{name: string, sourceRef: string, recoveredPhone: string}> */
    public array $waitingPhoneRecoveredSamples = [];

    /** @var list<array{rawName: string, sourceRef: string, candidates: list<array{phone: string, names: list<string>}>}> multiple plausible REGISTRATION matches by name alone — never auto-resolved */
    public array $waitingNameMatchAmbiguous = [];

    public int $duplicateGroupsCount = 0;

    public int $cancelledCount = 0;

    public int $waitingCount = 0;

    public int $trainingCount = 0;

    public int $practicalCount = 0;

    public int $activeCount = 0;

    /**
     * CONFIRMED: "Other Contact"/"Other Contact Name" are the employee's own secondary/
     * emergency contact, NEVER the guarantor — Excel has no guarantor data at all. Kept
     * entirely separate from every guarantor-rule metric below.
     */
    public int $secondaryContactCapturedCount = 0;

    public int $supervisorCandidateCount = 0;

    public int $missingLocationCount = 0;

    /**
     * CONFIRMED: a null application_date is never a manual-review blocker by itself any
     * more (for anyone — REGISTRATION-matched or a new candidate) — this always reads 0
     * now; kept for backward compatibility with the printed summary rather than removed.
     */
    public int $missingDateReviewCount = 0;

    /** Count of people whose application_date came from RegistrationDateInferrer, not directly from their own row. */
    public int $registrationDateInferredCount = 0;

    /** Total people (ready + review) whose application_date is null — informational only, never a blocker. */
    public int $applicationDateNullCount = 0;

    /** @var list<ResolvedPerson> */
    public array $registrationDateInferredSamples = [];

    public int $ambiguousCategoryReviewCount = 0;

    public int $ambiguousStageReviewCount = 0;

    public int $phoneInvalidReviewCount = 0;

    /** @var array<string, int> raw job text => occurrences, among people whose category didn't match */
    public array $unmatchedCategoryValues = [];

    /** @var array<string, int> "Full Time – Jiif" / "Part Time – Maalin" / "(unspecified)" => person count, among Home Team category people */
    public array $homeCleaningWorkTypeCounts = [];

    /** @var array<string, int> "Cook" / "Cunto & Nadaafad" => person count, among Cooking category people */
    public array $cookingSpecializationCounts = [];

    /** @var array<string, int> final matchedCategory name => person count, across everyone (confident matches AND the General Cleaning catch-all combined) */
    public array $matchedCategoryCounts = [];

    /** Count of people whose category came from the General Cleaning catch-all (no confident keyword match) rather than a real keyword. */
    public int $categoryFallbackToGeneralCleaningCount = 0;

    /**
     * @var array<string, array{rows: int, matchedToRegistration: int, newCandidates: int, manualReview: int, sameSheetDuplicateRows: int}>
     *                                                                                                                                      Per-sheet summary across every employee-source sheet actually present in the workbook.
     */
    public array $sheetSummary = [];

    /** @var list<array{name: string, phone: string, matchedAgainst: list<string>}> */
    public array $mogadishuHospitalMatches = [];

    public int $mogadishuHospitalRowCount = 0;

    public int $studentPracticalRowCount = 0;

    public int $excludedPayrollRowCount = 0;

    public int $excludedJobOrdersRowCount = 0;

    public int $excludedKormeerRowCount = 0;

    /**
     * @var array<string, bool> sheet name => present in this workbook at all. A "0" row
     *                          count elsewhere for one of these sheets is ambiguous between "empty" and
     *                          "doesn't exist" — this disambiguates it explicitly.
     */
    public array $otherSheetPresence = [];

    // --- Color audit (REGISTRATION only — confirmed business signal, see RegistrationColorReader) ---

    public int $registrationRedRowCount = 0;

    public int $canceledSheetRowCount = 0;

    public int $redAndCanceledOverlapCount = 0;

    public int $canceledSheetOnlyCount = 0;

    /** Explicit, per-row, person-level reconciliation of the two cancellation sources — see the class. */
    public ?CancellationReconciliation $cancellationReconciliation = null;

    public int $yellowRowCount = 0;

    /** @var array<string, int> green RGB shade => row count */
    public array $greenShadeCounts = [];

    public int $greenTotalRowCount = 0;

    // --- Color-rule reconciliation: "other color" and "no fill" both mean NEED DAMIIN ---

    public int $registrationOtherColorRowCount = 0;

    public int $registrationNoFillRowCount = 0;

    // --- Guarantor-rule reconciliation: NEED (independent flag) vs PROVIDED (yellow) ---
    // CONFIRMED: Excel contains no actual guarantor/Damiin data for anyone — there is no
    // "actual guarantor record" metric here by design. The real guarantor is always added
    // later via Employee Profile → Guarantor/Damiin → Add Guarantor.

    /** Final derived guarantor_need = YES, after green/yellow/waiting overrides. */
    public int $guarantorNeedCount = 0;

    /** Yellow-flagged ("Damiin ayuu keensaday") — guarantor already provided per source (context only). */
    public int $guarantorProvidedCount = 0;

    public int $damiinOverriddenByGreenCount = 0;

    public int $damiinOverriddenByYellowCount = 0;

    public int $damiinOverriddenByWaitingCount = 0;

    /** Green-flagged person whose Stage said "need training" — suppressed per the confirmed override rule. */
    public int $trainingOverriddenByGreenCount = 0;

    // --- Profile completeness (dry-run reporting only — no schema/live-write impact) ---

    /** Always equals unique_people — every Excel-migrated person has an incomplete HR profile by definition. */
    public int $profileIncompleteCount = 0;

    // --- wiilasha vs REGISTRATION relationship (wiilasha is a shortcut list, not a second registration) ---

    public int $wiilashaTotalCount = 0;

    public int $wiilashaMatchedCount = 0;

    public int $wiilashaNewCandidateCount = 0;

    public int $wiilashaUncertainCount = 0;

    /** @var list<ResolvedPerson> */
    public array $wiilashaNewCandidateSamples = [];

    // --- Waiting List vs REGISTRATION relationship ---

    public int $waitingListTotalCount = 0;

    public int $waitingListMatchedCount = 0;

    public int $waitingListUnmatchedCount = 0;

    public int $registrationDateRecoveredForWaitingCount = 0;

    public int $waitingDateRecoveredCount = 0;

    /** @var list<array{term: string, sheet: string, column: string, example: string, occurrences: int, possibleInterpretation: ?string, whyUncertain: string}> */
    public array $somaliTermsRequiringClarification = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_rows_scanned' => $this->totalRowsScanned,
            'real_person_rows' => $this->realPersonRows,
            'unique_people' => count($this->readyToImport) + count($this->manualReview),
            'duplicate_groups' => $this->duplicateGroupsCount,
            'ready_to_import' => count($this->readyToImport),
            'manual_review' => count($this->manualReview),
            'missing_name' => $this->missingNameCount,
            'missing_phone' => $this->missingPhoneCount,
            'missing_location' => $this->missingLocationCount,
            'missing_date_blocking' => $this->missingDateReviewCount,
            'application_date_null_total' => $this->applicationDateNullCount,
            'registration_dates_inferred_from_neighbors' => $this->registrationDateInferredCount,
            'waiting_phones_recovered_via_registration_name_match' => $this->waitingPhoneRecoveredViaNameMatchCount,
            'waiting_no_phone_multiple_plausible_matches' => count($this->waitingNameMatchAmbiguous),
            'ambiguous_category_blocking' => $this->ambiguousCategoryReviewCount,
            'ambiguous_stage_blocking' => $this->ambiguousStageReviewCount,
            'invalid_phone_format_blocking' => $this->phoneInvalidReviewCount,
            'phone_conflict_groups' => count($this->phoneConflicts),
            'cancelled' => $this->cancelledCount,
            'waiting' => $this->waitingCount,
            'training' => $this->trainingCount,
            'practical' => $this->practicalCount,
            'active_working' => $this->activeCount,
            'secondary_contacts_captured' => $this->secondaryContactCapturedCount,
            'supervisor_candidates' => $this->supervisorCandidateCount,
            'unmatched_category_values' => $this->unmatchedCategoryValues,
            'matched_category_counts' => $this->matchedCategoryCounts,
            'category_fallback_to_general_cleaning' => $this->categoryFallbackToGeneralCleaningCount,
            'home_cleaning_work_type_counts' => $this->homeCleaningWorkTypeCounts,
            'cooking_specialization_counts' => $this->cookingSpecializationCounts,
            'mogadishu_hospital_row_count' => $this->mogadishuHospitalRowCount,
            'mogadishu_hospital_possible_matches' => count($this->mogadishuHospitalMatches),
            'student_practical_row_count' => $this->studentPracticalRowCount,
            'excluded_payroll_rows' => $this->excludedPayrollRowCount,
            'excluded_job_orders_rows' => $this->excludedJobOrdersRowCount,
            'excluded_kormeer_rows' => $this->excludedKormeerRowCount,
            'registration_red_rows' => $this->registrationRedRowCount,
            'canceled_sheet_rows' => $this->canceledSheetRowCount,
            'red_and_canceled_overlap' => $this->redAndCanceledOverlapCount,
            'canceled_sheet_only' => $this->canceledSheetOnlyCount,
            'yellow_rows_damiin_provided' => $this->yellowRowCount,
            'green_rows_total_worked_or_sent' => $this->greenTotalRowCount,
            'green_shade_breakdown' => $this->greenShadeCounts,
            'registration_other_color_rows' => $this->registrationOtherColorRowCount,
            'registration_no_fill_rows' => $this->registrationNoFillRowCount,
            'guarantor_need_count' => $this->guarantorNeedCount,
            'guarantor_provided_count' => $this->guarantorProvidedCount,
            'damiin_overridden_by_green' => $this->damiinOverriddenByGreenCount,
            'damiin_overridden_by_yellow' => $this->damiinOverriddenByYellowCount,
            'damiin_overridden_by_waiting' => $this->damiinOverriddenByWaitingCount,
            'training_overridden_by_green' => $this->trainingOverriddenByGreenCount,
            'profile_incomplete_count' => $this->profileIncompleteCount,
            'wiilasha_total' => $this->wiilashaTotalCount,
            'wiilasha_matched_to_registration' => $this->wiilashaMatchedCount,
            'wiilasha_new_candidates' => $this->wiilashaNewCandidateCount,
            'wiilasha_uncertain' => $this->wiilashaUncertainCount,
            'waiting_list_total' => $this->waitingListTotalCount,
            'waiting_list_matched_to_registration' => $this->waitingListMatchedCount,
            'waiting_list_unmatched' => $this->waitingListUnmatchedCount,
            'registration_date_recovered_for_waiting' => $this->registrationDateRecoveredForWaitingCount,
            'waiting_date_recovered' => $this->waitingDateRecoveredCount,
            'somali_terms_requiring_clarification' => count($this->somaliTermsRequiringClarification),
        ];
    }
}
