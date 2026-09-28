<?php

namespace App\Support\Hr\ExcelMigration;

use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeStatus;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Parses, normalizes, deduplicates and classifies the HR Excel workbook.
 * READ-ONLY: nothing in this class writes to the database. `analyze()` is
 * the dry-run entry point; a future commit mode is a separate concern (see
 * MigrateExcelHrDataCommand) that would consume this service's output.
 *
 * Confirmed business rules this class encodes (not assumptions):
 * - REGISTRATION is the master registration source; its Date is the
 *   person's original registration date and is never substituted.
 * - wiilasha is a shortcut/derived list, not a second registration — a
 *   wiilasha row found in REGISTRATION is the same person (REGISTRATION's
 *   date wins); a wiilasha row NOT found in REGISTRATION is still a
 *   legitimate new registration candidate, never discarded.
 * - Waiting List's own date is a different event ("waiting_since") from
 *   the registration date and is kept in a separate field.
 * - RED REGISTRATION highlight OR CANCELED-sheet membership = cancelled;
 *   the final cancelled population is the UNION of both sources.
 * - YELLOW REGISTRATION highlight = the person already brought a
 *   guarantor (context only — never forces pipeline_stage=damiin_needed).
 * - Any of the 4 confirmed GREEN shades = "works, or was previously sent
 *   to work" — preserved as context, never auto-promotes to Active, and
 *   overrides any "need training"/"guarantor needed" signal to NO.
 * - Every other REGISTRATION color, and no fill at all, = guarantor needed.
 * - Guarantor-NEED is an INDEPENDENT boolean (ResolvedPerson::$guarantorNeedFlag),
 *   never a pipelineStage — it is never lost because another stage (e.g.
 *   need_training) outranks it. Waiting-List status also overrides it to NO,
 *   same as green and yellow (already provided).
 * - A cancellation reason is only ever taken from the sheet's designated
 *   reason field, filtered for known non-reason noise ("paid"/"piad"/amounts)
 *   — Stage text (Ready/Stop/Need Training/etc.) is never treated as a reason.
 * - "Xaafad"/"Xafad"/"xafad" (any casing) always means Home Cleaning.
 */
final class ExcelMigrationService
{
    /** Sheets whose own Date column is a different event from registration — see $waitingSince. */
    private const array WAITING_DATE_SHEETS = ['Waiting List', 'NEW WAITING LIST2026'];

    /**
     * CONFIRMED business decision: sheets where a no-phone row may be recovered via a
     * conservative REGISTRATION name match (Location as tie-breaker only) — originally
     * Waiting List only, extended to Home Cleaning and wiilasha. See
     * recoverPhoneByRegistrationNameMatch().
     */
    private const array PHONE_RECOVERY_ELIGIBLE_SHEETS = ['Waiting List', 'NEW WAITING LIST2026', 'Home Cleaning', 'wiilasha'];

    /** Both current and legacy names for the explicit cancellation sheet — see SheetConfig. */
    private const array CANCELLED_SHEET_NAMES = ['CANCELED', 'KUWA LA KANSALEY'];

    /** Both current and legacy names for the explicit "guarantor needed" sheet — see SheetConfig. */
    private const array DAMIIN_SHEET_NAMES = ['NEED DAMIIN', 'DAMIIN WALI KEENIN'];

    /**
     * Specific Somali/local terms actually observed in the workbook whose
     * Fayadhowr business meaning is not confirmed. Deliberately a reviewed,
     * static list (not a generated "looks unusual" heuristic) — every entry
     * here was read directly off real rows during the inspection pass.
     * Excludes terms whose meaning IS confirmed (Xaafad/Xafad/xafad, Garoob).
     *
     * @var list<array{term: string, column: string, possibleInterpretation: ?string}>
     */
    private const array UNCLEAR_TERMS = [
        ['term' => 'Hubin', 'column' => 'Stage', 'possibleInterpretation' => 'Possibly "verification/confirmation" — unconfirmed'],
        ['term' => 'Sanbus dubow', 'column' => 'Stage', 'possibleInterpretation' => null],
        ['term' => 'Harqan Tolow', 'column' => 'Stage', 'possibleInterpretation' => null],
        ['term' => 'MCH Talaal', 'column' => 'Stage', 'possibleInterpretation' => 'Possibly a health/vaccination qualification note — unconfirmed'],
        ['term' => 'Boqorada', 'column' => 'Reference/Experience', 'possibleInterpretation' => 'Possibly a place or referrer name — unconfirmed'],
        ['term' => 'degandhowr', 'column' => 'Stage/Experience', 'possibleInterpretation' => null],
        ['term' => 'Sheytari dhan', 'column' => 'Job', 'possibleInterpretation' => null],
        ['term' => 'Cabitaanle', 'column' => 'Job', 'possibleInterpretation' => 'Possibly a beverage/drinks-service role — unconfirmed'],
    ];

    private SheetRowParser $rowParser;

    public function __construct()
    {
        $this->rowParser = new SheetRowParser;
    }

    public function loadWorkbook(string $path): Spreadsheet
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        return $reader->load($path);
    }

    /**
     * Raw REGISTRATION Date column, by row number, for the entire sheet — used only by
     * RegistrationDateInferrer to look at surrounding rows when a row's own date fails
     * to parse. Deliberately independent of SheetRowParser (which skips blank-name rows
     * entirely) since a genuine section-marker or blank row is itself useful "no
     * evidence here" information for the inference algorithm.
     *
     * @return array<int, ?string>
     */
    private function buildRegistrationRawDatesByRow(Spreadsheet $spreadsheet): array
    {
        $sheet = $spreadsheet->getSheetByName('REGISTRATION');

        if ($sheet === null) {
            return [];
        }

        $result = [];
        $highestRow = $sheet->getHighestDataRow();

        for ($row = 1; $row <= $highestRow; $row++) {
            $value = $sheet->getCell('A'.$row)->getValue();
            $result[$row] = ($value === null || ! is_scalar($value)) ? null : trim((string) $value);
        }

        return $result;
    }

    /**
     * @param  list<string>  $existingCategoryNames  read-only snapshot of employee_categories.name
     * @param  array<int, array{isRed: bool, isYellow: bool, isGreen: bool, greenShade: ?string}>  $registrationColors  from RegistrationColorReader
     */
    public function analyze(Spreadsheet $spreadsheet, array $existingCategoryNames, array $registrationColors = []): ExcelMigrationReport
    {
        $report = new ExcelMigrationReport;
        $configRegistry = SheetConfig::registry();
        $registrationRawDatesByRow = $this->buildRegistrationRawDatesByRow($spreadsheet);

        /** @var list<RawExcelRow> $allRows */
        $allRows = [];

        foreach ($configRegistry as $sheetName => $config) {
            $sheet = $spreadsheet->getSheetByName($sheetName);

            if ($sheet === null) {
                continue; // workbook structure changed since the design was reviewed — skip, don't guess
            }

            $rows = $this->rowParser->parse($sheet, $config);
            $lastRow = $config->endRow !== null ? min($config->endRow, $sheet->getHighestDataRow()) : $sheet->getHighestDataRow();
            $report->totalRowsScanned += max(0, $lastRow - $config->startRow + 1);
            $allRows = [...$allRows, ...$rows];
        }

        // CONFIRMED: "Waiting List" is really two differently-laid-out blocks glued
        // together — see parseWaitingListSecondBlock() for the full explanation. Merged
        // in here, tagged with the same sheetName, so every sheet-name-based rule
        // (WAITING_DATE_SHEETS, no-need-training, no-need-damiin) applies identically.
        $waitingSecondBlockRows = $this->parseWaitingListSecondBlock($spreadsheet, $report);
        $allRows = [...$allRows, ...$waitingSecondBlockRows];

        $report->realPersonRows = count($allRows);

        $this->computeColorAuditStats($allRows, $registrationColors, $report);
        $this->computeSomaliTermsReport($allRows, $report);

        [$groups, $noValidPhoneCount, $missingName] = $this->groupByPhone($allRows, $report);
        $report->missingPhoneCount = $noValidPhoneCount;
        $report->missingNameCount = count($missingName);

        $reconciliation = new CancellationReconciliation;

        foreach ($missingName as $row) {
            $this->tallyReconciliationRow($row, $registrationColors, $reconciliation, 'C', 'Blank row — no name at all, cannot become an Employee candidate.');
        }

        foreach ($groups as $normalizedPhone => $rowsForPhone) {
            if (count($rowsForPhone) > 1) {
                $report->duplicateGroupsCount++;
            }

            if (! $this->allNamesCompatible($rowsForPhone)) {
                $report->phoneConflicts[] = new PhoneConflictGroup(
                    normalizedPhone: $normalizedPhone,
                    entries: array_map(
                        fn (RawExcelRow $r): array => ['sheet' => $r->sheetName, 'row' => $r->rowNumber, 'name' => (string) $r->name],
                        $rowsForPhone,
                    ),
                );

                foreach ($rowsForPhone as $row) {
                    if ($row->sheetName === 'wiilasha') {
                        $report->wiilashaUncertainCount++;
                    }

                    $this->tallyReconciliationRow($row, $registrationColors, $reconciliation, 'D', 'Shares a phone number with a non-matching name elsewhere — withheld for manual review, not auto-merged.');
                }

                continue;
            }

            // Only rows that are THEMSELVES a cancellation source (red or CANCELED-sheet)
            // count toward the reconciliation — a row must not be classified "duplicate of
            // another row" just because an ordinary, non-cancelled REGISTRATION row for the
            // same person happens to appear earlier in this group. That was a real bug caught
            // by testing against the actual workbook before this went into a report: a
            // CANCELED-sheet row whose only "earlier" group member was an unrelated ordinary
            // registration row was wrongly counted as a duplicate instead of the sole signal it is.
            $cancellationSourceRowsInGroup = array_values(array_filter(
                $rowsForPhone,
                fn (RawExcelRow $r): bool => $this->isCancellationSourceRow($r, $registrationColors),
            ));

            foreach ($cancellationSourceRowsInGroup as $index => $row) {
                if ($index === 0) {
                    $classification = str_starts_with($normalizedPhone, 'no-phone:') ? 'E' : 'A';
                    $reason = $classification === 'E'
                        ? 'No valid phone number — identity unverified against other sheets, but still resolved as its own cancelled candidate.'
                        : 'Valid, uniquely identified cancelled person.';
                } else {
                    $classification = 'B';
                    $reason = 'Same person as another cancellation-source row sharing this phone number — correctly counted once, not twice.';
                }

                $this->tallyReconciliationRow($row, $registrationColors, $reconciliation, $classification, $reason);
            }

            $person = $this->resolveGroup($normalizedPhone, $rowsForPhone, $existingCategoryNames, $configRegistry, $registrationColors, $report, $registrationRawDatesByRow);

            if ($person->isCancellation) {
                $hasRed = array_any($person->cancellationSources, fn (string $s): bool => str_starts_with($s, 'RED fill'));
                $hasCanceled = array_any($person->cancellationSources, fn (string $s): bool => str_starts_with($s, 'CANCELED sheet'));

                if ($hasRed && $hasCanceled) {
                    $reconciliation->overlapPersons++;
                }
            }

            if ($person->isReadyToImport) {
                $report->readyToImport[] = $person;
            } else {
                $report->manualReview[] = $person;

                foreach ($person->reviewReasons as $reason) {
                    match (true) {
                        str_contains($reason, 'date') => $report->missingDateReviewCount++,
                        str_contains($reason, 'category') => $report->ambiguousCategoryReviewCount++,
                        str_contains($reason, 'stage') => $report->ambiguousStageReviewCount++,
                        str_contains($reason, 'phone number'), str_contains($reason, 'phone format') => $report->phoneInvalidReviewCount++,
                        default => null,
                    };
                }

                if (in_array('Student Practical training', $person->sourceSheets, true)) {
                    $report->studentPracticalRowCount++;
                }
            }

            if ($person->location === null) {
                $report->missingLocationCount++;
            }

            // "Unmatched" now means "not a confident keyword match" (fell through to the
            // General Cleaning catch-all) rather than "no category at all" — category is
            // never actually left null any more when General Cleaning exists. Kept under
            // the same field name/report section for continuity; the raw Job text is what
            // matters for audit purposes, not whether it happened to block anything.
            if (! $person->matchedCategoryIsConfident && $person->rawJobTitle !== null) {
                $key = mb_strtolower(trim($person->rawJobTitle));
                $report->unmatchedCategoryValues[$key] = ($report->unmatchedCategoryValues[$key] ?? 0) + 1;
            }

            if ($person->matchedCategory !== null) {
                $report->matchedCategoryCounts[$person->matchedCategory] = ($report->matchedCategoryCounts[$person->matchedCategory] ?? 0) + 1;
            }

            if ($person->isCancellation) {
                $report->cancelledCount++;
            } elseif ($person->status === EmployeeStatus::Waiting) {
                $report->waitingCount++;
            } elseif ($person->pipelineStage === EmployeePipelineStage::NeedTraining) {
                $report->trainingCount++;
            } elseif (in_array($person->pipelineStage, [EmployeePipelineStage::NeedPractical, EmployeePipelineStage::PracticalRepeat], true)
                || $person->status === EmployeeStatus::Practical) {
                $report->practicalCount++;
            } elseif ($person->status === EmployeeStatus::Active) {
                $report->activeCount++;
            }

            // CONFIRMED: Other Contact is the employee's own secondary/emergency contact,
            // NEVER the guarantor — tallied entirely separately from the guarantor-need/
            // guarantor-provided signals, which never have an "actual record" from Excel at all.
            if ($person->secondaryContactPhone !== null || $person->secondaryContactName !== null) {
                $report->secondaryContactCapturedCount++;
            }

            if ($person->guarantorNeedFlag) {
                $report->guarantorNeedCount++;
            }

            if ($person->isYellowFlagged) {
                $report->guarantorProvidedCount++;
            }

            $report->profileIncompleteCount++;

            if ($person->applicationDateInferred) {
                $report->registrationDateInferredCount++;
                $report->registrationDateInferredSamples[] = $person;
            }

            // CONFIRMED: application_date = NULL is acceptable and never blocks readiness
            // on its own — this is a direct, unconditional tally (not reason-driven, since
            // a null date is never added to reviewReasons any more) purely for visibility.
            if ($person->applicationDate === null) {
                $report->applicationDateNullCount++;
            }

            if ($person->isSupervisor) {
                $report->supervisorCandidateCount++;
            }
        }

        $allResolvedForMatching = [...$report->readyToImport, ...$report->manualReview];
        $this->buildWiilashaReport($allResolvedForMatching, $report);
        $this->buildWaitingListReport($allResolvedForMatching, $report);
        $this->handleMogadishuHospital($spreadsheet, $report, $allResolvedForMatching);
        $this->countExcludedSheets($spreadsheet, $report);
        $this->buildSheetSummary($allRows, $allResolvedForMatching, $spreadsheet, $report);
        $report->cancellationReconciliation = $reconciliation;

        return $report;
    }

    /**
     * @param  array<int, array{isRed: bool, isYellow: bool, isGreen: bool, greenShade: ?string}>  $registrationColors
     */
    private function isCancellationSourceRow(RawExcelRow $row, array $registrationColors): bool
    {
        $isRedRow = $row->sheetName === 'REGISTRATION' && ($registrationColors[$row->rowNumber]['isRed'] ?? false);
        $isCanceledSheetRow = in_array($row->sheetName, self::CANCELLED_SHEET_NAMES, true);

        return $isRedRow || $isCanceledSheetRow;
    }

    /**
     * Only tallies rows that are actually a confirmed cancellation source (RED REGISTRATION
     * highlight or CANCELED-sheet membership) — a no-op for every other row.
     *
     * @param  array<int, array{isRed: bool, isYellow: bool, isGreen: bool, greenShade: ?string}>  $registrationColors
     */
    private function tallyReconciliationRow(RawExcelRow $row, array $registrationColors, CancellationReconciliation $reconciliation, string $classification, string $reason): void
    {
        if (! $this->isCancellationSourceRow($row, $registrationColors)) {
            return;
        }

        $isRedRow = $row->sheetName === 'REGISTRATION' && ($registrationColors[$row->rowNumber]['isRed'] ?? false);
        $isCanceledSheetRow = in_array($row->sheetName, self::CANCELLED_SHEET_NAMES, true);

        if ($isRedRow) {
            $reconciliation->redRowsTotal++;
            match ($classification) {
                'A' => $reconciliation->redClassA++,
                'B' => $reconciliation->redClassB++,
                'C' => $reconciliation->redClassC++,
                'D' => $reconciliation->redClassD++,
                'E' => $reconciliation->redClassE++,
                default => null,
            };
        }

        if ($isCanceledSheetRow) {
            $reconciliation->canceledRowsTotal++;
            match ($classification) {
                'A' => $reconciliation->canceledClassA++,
                'B' => $reconciliation->canceledClassB++,
                'C' => $reconciliation->canceledClassC++,
                'D' => $reconciliation->canceledClassD++,
                'E' => $reconciliation->canceledClassE++,
                default => null,
            };
        }

        $reconciliation->rowDetails[] = [
            'sheet' => $row->sheetName,
            'row' => $row->rowNumber,
            'name' => (string) ($row->name ?? '(blank)'),
            'classification' => $classification,
            'reason' => $reason,
        ];
    }

    /**
     * Raw color counts independent of dedup — matches what was confirmed with the business
     * owner: red/yellow/green totals on REGISTRATION, and the CANCELED-sheet ∪ red-rows union.
     *
     * @param  list<RawExcelRow>  $allRows
     * @param  array<int, array{isRed: bool, isYellow: bool, isGreen: bool, greenShade: ?string}>  $registrationColors
     */
    private function computeColorAuditStats(array $allRows, array $registrationColors, ExcelMigrationReport $report): void
    {
        $redPhones = [];
        $canceledSheetPhones = [];

        foreach ($allRows as $row) {
            if ($row->sheetName === 'REGISTRATION') {
                $flags = $registrationColors[$row->rowNumber] ?? null;

                if ($flags !== null) {
                    if ($flags['isRed']) {
                        $report->registrationRedRowCount++;
                        $phone = PhoneNormalizer::normalize($row->phoneRaw)['primary'];

                        if ($phone !== null) {
                            $redPhones[$phone] = true;
                        }
                    }

                    if ($flags['isYellow']) {
                        $report->yellowRowCount++;
                    }

                    if ($flags['isGreen']) {
                        $report->greenTotalRowCount++;
                        $shade = (string) $flags['greenShade'];
                        $report->greenShadeCounts[$shade] = ($report->greenShadeCounts[$shade] ?? 0) + 1;
                    }

                    if ($flags['isDamiinNeeded']) {
                        if (($flags['colorContext'] ?? null) === 'no-fill') {
                            $report->registrationNoFillRowCount++;
                        } else {
                            $report->registrationOtherColorRowCount++;
                        }
                    }
                }
            }

            if (in_array($row->sheetName, self::CANCELLED_SHEET_NAMES, true)) {
                $report->canceledSheetRowCount++;
                $phone = PhoneNormalizer::normalize($row->phoneRaw)['primary'];

                if ($phone !== null) {
                    $canceledSheetPhones[$phone] = true;
                }
            }
        }

        $report->redAndCanceledOverlapCount = count(array_intersect_key($redPhones, $canceledSheetPhones));
        $report->canceledSheetOnlyCount = count(array_diff_key($canceledSheetPhones, $redPhones));
    }

    /**
     * @param  list<RawExcelRow>  $allRows
     */
    private function computeSomaliTermsReport(array $allRows, ExcelMigrationReport $report): void
    {
        foreach (self::UNCLEAR_TERMS as $termDef) {
            $needle = mb_strtolower($termDef['term']);
            $occurrences = 0;
            $example = null;

            foreach ($allRows as $row) {
                $haystack = mb_strtolower((string) ($row->stageRaw ?? '').' '.(string) ($row->jobRaw ?? '').' '.(string) ($row->experience ?? '').' '.(string) ($row->reference ?? ''));

                if (str_contains($haystack, $needle)) {
                    $occurrences++;

                    if ($example === null) {
                        $example = "{$row->sheetName}:{$row->rowNumber} — {$row->name}";
                    }
                }
            }

            if ($occurrences > 0) {
                $report->somaliTermsRequiringClarification[] = [
                    'term' => $termDef['term'],
                    'sheet' => 'REGISTRATION (and related sheets)',
                    'column' => $termDef['column'],
                    'example' => $example ?? '',
                    'occurrences' => $occurrences,
                    'possibleInterpretation' => $termDef['possibleInterpretation'],
                    'whyUncertain' => 'Local/Somali operational term with no confirmed Fayadhowr business meaning.',
                ];
            }
        }
    }

    /**
     * @param  list<ResolvedPerson>  $allResolved
     */
    private function buildWiilashaReport(array $allResolved, ExcelMigrationReport $report): void
    {
        foreach ($allResolved as $person) {
            if (! in_array('wiilasha', $person->sourceSheets, true)) {
                continue;
            }

            $report->wiilashaTotalCount++;

            if (in_array('REGISTRATION', $person->sourceSheets, true)) {
                $report->wiilashaMatchedCount++;
            } else {
                $report->wiilashaNewCandidateCount++;

                if (count($report->wiilashaNewCandidateSamples) < 20) {
                    $report->wiilashaNewCandidateSamples[] = $person;
                }
            }
        }
    }

    /**
     * @param  list<ResolvedPerson>  $allResolved
     */
    private function buildWaitingListReport(array $allResolved, ExcelMigrationReport $report): void
    {
        foreach ($allResolved as $person) {
            $inWaitingFamily = array_any(
                self::WAITING_DATE_SHEETS,
                fn (string $sheet): bool => in_array($sheet, $person->sourceSheets, true),
            );

            if (! $inWaitingFamily) {
                continue;
            }

            $report->waitingListTotalCount++;

            if (in_array('REGISTRATION', $person->sourceSheets, true)) {
                $report->waitingListMatchedCount++;

                if ($person->applicationDate !== null) {
                    $report->registrationDateRecoveredForWaitingCount++;
                }
            } else {
                $report->waitingListUnmatchedCount++;
            }

            if ($person->waitingSince !== null) {
                $report->waitingDateRecoveredCount++;
            }
        }
    }

    /**
     * Generic per-sheet summary across every employee-source sheet: raw rows, how many
     * rows shared a phone with an earlier row in the SAME sheet ("same-sheet duplicate"
     * — distinct from cross-sheet person-matching, which readyToImport/manualReview
     * already reflect), how many resolved people from that sheet matched an existing
     * REGISTRATION record vs. were legitimate new candidates, and how many landed in
     * manual review. REGISTRATION itself is the master source, not "matched to" anything.
     *
     * @param  list<RawExcelRow>  $allRows
     * @param  list<ResolvedPerson>  $allResolved
     */
    private function buildSheetSummary(array $allRows, array $allResolved, Spreadsheet $spreadsheet, ExcelMigrationReport $report): void
    {
        $employeeSourceSheets = [
            'REGISTRATION', 'Home Cleaning', 'Cooking Centre', 'Waiters Centre', 'Supervisors',
            'wiilasha', 'Waiting List', 'NEW WAITING LIST2026', 'NEED DAMIIN', 'CANCELED',
            'DAMIIN WALI KEENIN', 'KUWA LA KANSALEY',
        ];

        $rowsPerSheet = [];
        $phonesSeenPerSheet = [];
        $duplicateRowsPerSheet = [];

        foreach ($allRows as $row) {
            $rowsPerSheet[$row->sheetName] = ($rowsPerSheet[$row->sheetName] ?? 0) + 1;

            $phone = PhoneNormalizer::normalize($row->phoneRaw)['primary'];

            if ($phone === null) {
                continue;
            }

            $seen = $phonesSeenPerSheet[$row->sheetName] ?? [];

            if (isset($seen[$phone])) {
                $duplicateRowsPerSheet[$row->sheetName] = ($duplicateRowsPerSheet[$row->sheetName] ?? 0) + 1;
            }

            $seen[$phone] = true;
            $phonesSeenPerSheet[$row->sheetName] = $seen;
        }

        $matchedPerSheet = [];
        $newPerSheet = [];
        $manualReviewPerSheet = [];

        foreach ($allResolved as $person) {
            foreach ($person->sourceSheets as $sheetName) {
                if ($sheetName === 'REGISTRATION') {
                    continue;
                }

                if (in_array('REGISTRATION', $person->sourceSheets, true)) {
                    $matchedPerSheet[$sheetName] = ($matchedPerSheet[$sheetName] ?? 0) + 1;
                } else {
                    $newPerSheet[$sheetName] = ($newPerSheet[$sheetName] ?? 0) + 1;
                }

                if (! $person->isReadyToImport) {
                    $manualReviewPerSheet[$sheetName] = ($manualReviewPerSheet[$sheetName] ?? 0) + 1;
                }
            }
        }

        foreach ($employeeSourceSheets as $sheetName) {
            $sheet = $spreadsheet->getSheetByName($sheetName);

            if ($sheet === null && ! isset($rowsPerSheet[$sheetName])) {
                continue; // not present in this workbook at all — skip rather than show a fake zero row
            }

            $report->sheetSummary[$sheetName] = [
                'rows' => $rowsPerSheet[$sheetName] ?? 0,
                'matchedToRegistration' => $matchedPerSheet[$sheetName] ?? 0,
                'newCandidates' => $newPerSheet[$sheetName] ?? 0,
                'manualReview' => $manualReviewPerSheet[$sheetName] ?? 0,
                'sameSheetDuplicateRows' => $duplicateRowsPerSheet[$sheetName] ?? 0,
            ];
        }
    }

    /**
     * CONFIRMED business decision: the "Waiting List" sheet is really TWO differently
     * laid-out blocks glued together. A "NEW WAITING LIST" section marker at row 85
     * introduces a second block (confirmed to start at row 92) whose column layout has
     * NO Date column at all — Name/Phone/Job/Location are shifted one position earlier
     * than the first block, which is exactly why every person in this block was
     * previously misread (their real name read as a date, their real phone read as a
     * name, their real job read as a phone, their real location read as a job).
     *
     * Confirmed corrected layout: A=Name, B=Phone, C=Job/Category, D=Location, E=Age,
     * F=Marital Status, G=Lives With, H=Reference, I=Stage, J=Experience,
     * K=Other Contact Phone, L=Other Contact Name, M=Other Info.
     *
     * Rows are tagged with the SAME sheetName ('Waiting List') as the first block so
     * every existing sheet-name-based rule (WAITING_DATE_SHEETS date handling, the
     * no-need-training/no-need-damiin override) applies identically — this is not a
     * separate sheet, just a second physical block within the same one.
     */
    private function parseWaitingListSecondBlock(Spreadsheet $spreadsheet, ExcelMigrationReport $report): array
    {
        $sheet = $spreadsheet->getSheetByName('Waiting List');

        if ($sheet === null) {
            return [];
        }

        $config = new SheetConfig(
            sheetName: 'Waiting List',
            startRow: 92,
            endRow: null,
            dateCol: null,
            nameCol: 1,
            phoneCol: 2,
            jobCol: 3,
            locationCol: 4,
            ageCol: 5,
            maritalStatusCol: 6,
            livesWithCol: 7,
            referenceCol: 8,
            stageCol: 9,
            experienceCol: 10,
            otherContactPhoneCol: 11,
            otherContactNameCol: 12,
            otherInfoCol: 13,
            trainingFeeCol: null,
        );

        $rows = $this->rowParser->parse($sheet, $config);
        $highestRow = $sheet->getHighestDataRow();
        $report->totalRowsScanned += max(0, $highestRow - $config->startRow + 1);

        return $rows;
    }

    /**
     * A row with a name but no valid phone still becomes its OWN single-row group (keyed
     * synthetically, since there is nothing to dedupe it against) rather than being silently
     * dropped from the person pipeline — this matters specifically because a CANCELED-sheet
     * or RED-flagged person with a missing/malformed phone must still surface as a cancelled
     * candidate for manual review, never vanish without a trace. Confirmed by investigation:
     * this is exactly why the raw 534+71-0=605 cancellation arithmetic didn't match the
     * previously-reported 586 — some of those rows had no valid phone and were being counted
     * only in missingPhoneCount, never resolved into a person at all.
     *
     * @param  list<RawExcelRow>  $rows
     * @return array{0: array<string, list<RawExcelRow>>, 1: int, 2: list<RawExcelRow>}
     */
    private function groupByPhone(array $rows, ExcelMigrationReport $report): array
    {
        $groups = [];
        $noValidPhoneCount = 0;
        $missingName = [];

        // CONFIRMED business decision: a Waiting-family row with no valid phone may be
        // recovered via a conservative REGISTRATION name match (Job/Location only ever
        // used as a supporting TIE-BREAKER, never as the primary signal) — REGISTRATION
        // remains master, no duplicate Employee is ever created this way, and a
        // genuinely ambiguous case (multiple plausible REGISTRATION matches) is never
        // auto-resolved, only flagged for manual review with every candidate shown.
        $registrationCandidates = [];

        foreach ($rows as $row) {
            if ($row->sheetName !== 'REGISTRATION' || $row->name === null) {
                continue;
            }

            $phone = PhoneNormalizer::normalize($row->phoneRaw);

            if ($phone['primary'] === null || ! $phone['isValidFormat']) {
                continue;
            }

            $registrationCandidates[] = [
                'name' => $row->name,
                'phone' => $phone['primary'],
                'location' => $row->location,
            ];
        }

        foreach ($rows as $row) {
            if ($row->name === null) {
                $missingName[] = $row;

                continue;
            }

            $phone = PhoneNormalizer::normalize($row->phoneRaw);

            if ($phone['primary'] === null || ! $phone['isValidFormat']) {
                $noValidPhoneCount++;

                if (in_array($row->sheetName, self::PHONE_RECOVERY_ELIGIBLE_SHEETS, true)) {
                    $recoveredPhone = $this->recoverPhoneByRegistrationNameMatch($row, $registrationCandidates, $report);

                    if ($recoveredPhone !== null) {
                        $groups[$recoveredPhone][] = $row;

                        continue;
                    }
                }

                $groups['no-phone:'.$row->sheetName.':'.$row->rowNumber] = [$row];

                continue;
            }

            $groups[$phone['primary']][] = $row;
        }

        return [$groups, $noValidPhoneCount, $missingName];
    }

    /**
     * @param  list<array{name: string, phone: string, location: ?string}>  $registrationCandidates
     */
    private function recoverPhoneByRegistrationNameMatch(RawExcelRow $row, array $registrationCandidates, ExcelMigrationReport $report): ?string
    {
        /** @var array<string, list<array{name: string, phone: string, location: ?string}>> $matchesByPhone */
        $matchesByPhone = [];

        foreach ($registrationCandidates as $candidate) {
            if (NameMatcher::isLikelySamePersonByNameAlone((string) $row->name, $candidate['name'])) {
                $matchesByPhone[$candidate['phone']][] = $candidate;
            }
        }

        if ($matchesByPhone === []) {
            return null;
        }

        if (count($matchesByPhone) === 1) {
            return $this->recordRecoveredPhone($row, array_key_first($matchesByPhone), $report);
        }

        // Multiple distinct REGISTRATION phones matched by name alone — Location is a
        // supporting, TIE-BREAKING signal only; never sufficient on its own.
        if ($row->location !== null) {
            $normalizedRowLocation = mb_strtolower(trim($row->location));
            $narrowedByLocation = array_filter(
                $matchesByPhone,
                fn (array $candidatesForPhone): bool => array_any(
                    $candidatesForPhone,
                    fn (array $c): bool => $c['location'] !== null && mb_strtolower(trim($c['location'])) === $normalizedRowLocation,
                ),
            );

            if (count($narrowedByLocation) === 1) {
                return $this->recordRecoveredPhone($row, array_key_first($narrowedByLocation), $report);
            }
        }

        // Still ambiguous — never guess. Flagged for manual review with every plausible
        // REGISTRATION match shown, per the confirmed business decision.
        $report->waitingNameMatchAmbiguous[] = [
            'rawName' => (string) $row->name,
            'sourceRef' => "{$row->sheetName}:{$row->rowNumber}",
            'candidates' => array_map(
                fn (string $phone, array $group): array => [
                    'phone' => $phone,
                    'names' => array_values(array_unique(array_column($group, 'name'))),
                ],
                array_keys($matchesByPhone),
                array_values($matchesByPhone),
            ),
        ];

        return null;
    }

    private function recordRecoveredPhone(RawExcelRow $row, string $phone, ExcelMigrationReport $report): string
    {
        $report->waitingPhoneRecoveredViaNameMatchCount++;
        $report->waitingPhoneRecoveredSamples[] = [
            'name' => (string) $row->name,
            'sourceRef' => "{$row->sheetName}:{$row->rowNumber}",
            'recoveredPhone' => $phone,
        ];

        return $phone;
    }

    /**
     * @param  list<RawExcelRow>  $rows
     */
    private function allNamesCompatible(array $rows): bool
    {
        $names = array_values(array_unique(array_map(fn (RawExcelRow $r): string => (string) $r->name, $rows)));

        for ($i = 1; $i < count($names); $i++) {
            if (! NameMatcher::areCompatible($names[0], $names[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<RawExcelRow>  $rows  all rows sharing this phone, names already confirmed compatible
     * @param  list<string>  $existingCategoryNames
     * @param  array<string, SheetConfig>  $configRegistry
     * @param  array<int, array{isRed: bool, isYellow: bool, isGreen: bool, greenShade: ?string}>  $registrationColors
     */
    /**
     * @param  array<int, ?string>  $registrationRawDatesByRow
     */
    private function resolveGroup(string $normalizedPhone, array $rows, array $existingCategoryNames, array $configRegistry, array $registrationColors, ExcelMigrationReport $report, array $registrationRawDatesByRow): ResolvedPerson
    {
        $reviewReasons = [];

        // CONFIRMED business decision (final): a missing/invalid phone is NEVER by itself
        // a manual-review blocker — phone simply stays null, never fabricated, and the
        // person is otherwise Ready to Import. The ONE exception is a genuine identity
        // conflict: this row was already tried against a conservative REGISTRATION name
        // match (see recoverPhoneByRegistrationNameMatch) and multiple plausible people
        // remained — that specific, narrow case is the only one that still blocks.
        if (str_starts_with($normalizedPhone, 'no-phone:') && count($rows) === 1) {
            $sourceRef = "{$rows[0]->sheetName}:{$rows[0]->rowNumber}";
            $isAmbiguousNameMatch = array_any(
                $report->waitingNameMatchAmbiguous,
                fn (array $case): bool => $case['sourceRef'] === $sourceRef,
            );

            if ($isAmbiguousNameMatch) {
                $reviewReasons[] = 'Multiple plausible REGISTRATION identities exist for this name and the available Excel data cannot safely distinguish them — remains Manual Review; never merged, never guessed.';
            }
        }

        $fullName = $this->firstNonNull($rows, fn (RawExcelRow $r) => $r->name) ?? '';
        $location = $this->firstNonNull($rows, fn (RawExcelRow $r) => $r->location);
        $maritalStatus = $this->firstNonNull($rows, fn (RawExcelRow $r) => $r->maritalStatus);
        $livesWith = $this->firstNonNull($rows, fn (RawExcelRow $r) => $r->livesWith);
        $source = $this->firstNonNull($rows, fn (RawExcelRow $r) => $r->reference);
        $experience = $this->firstNonNull($rows, fn (RawExcelRow $r) => $r->experience);
        $jobRaw = $this->firstNonNull($rows, fn (RawExcelRow $r) => $r->jobRaw);
        $isSupervisor = array_any($rows, fn (RawExcelRow $r): bool => $r->isSupervisorSheet);

        // CONFIRMED business rule: "Other Contact"/"Other Contact Name" are the EMPLOYEE's
        // own secondary/emergency contact — a second number to reach them by, NOT the
        // guarantor/Damiin. Excel contains no guarantor data at all; never populate an
        // actual guarantor record from this (or from anything else in this migration —
        // see ResolvedPerson::$secondaryContactPhone). Only migrated when reliable; an
        // unreliable raw value is preserved as context, never silently discarded.
        $secondaryContactPhoneRaw = $this->firstNonNull($rows, fn (RawExcelRow $r) => $r->otherContactPhone);
        $secondaryContactNameRaw = $this->firstNonNull($rows, fn (RawExcelRow $r) => $r->otherContactName);

        $secondaryContactPhone = null;
        $secondaryContactPhoneUnreliableNote = null;

        if ($secondaryContactPhoneRaw !== null) {
            if (PhoneNormalizer::normalize($secondaryContactPhoneRaw)['isValidFormat']) {
                $secondaryContactPhone = PhoneNormalizer::normalize($secondaryContactPhoneRaw)['primary'];
            } else {
                $secondaryContactPhoneUnreliableNote = "Other Contact Phone (unreliable format, not used as secondary contact): {$secondaryContactPhoneRaw}";
            }
        }

        $secondaryContactName = null;
        $secondaryContactNameUnreliableNote = null;

        if ($secondaryContactNameRaw !== null) {
            if (mb_strlen(trim($secondaryContactNameRaw)) >= 2 && preg_match('/\p{L}/u', $secondaryContactNameRaw) === 1) {
                $secondaryContactName = trim($secondaryContactNameRaw);
            } else {
                $secondaryContactNameUnreliableNote = "Other Contact Name (unreliable value, not used as secondary contact): {$secondaryContactNameRaw}";
            }
        }

        $ageRaw = $this->firstNonNull($rows, fn (RawExcelRow $r) => $r->age);
        $age = ($ageRaw !== null && ctype_digit($ageRaw)) ? (int) $ageRaw : null;

        // Registration/application date: REGISTRATION is the master source, and its date is
        // never substituted. Waiting-List-family dates are a DIFFERENT event (see $waitingSince
        // below) and are deliberately skipped here even if encountered first in iteration order.
        $applicationDate = null;
        $applicationDateInferred = false;
        $applicationDateInferenceNote = null;

        foreach ($rows as $row) {
            if (in_array($row->sheetName, self::WAITING_DATE_SHEETS, true)) {
                continue;
            }

            $parsed = DateParser::parse($row->dateRaw);

            if ($parsed !== null) {
                $applicationDate = $parsed;

                break;
            }
        }

        // CONFIRMED business decision: when REGISTRATION's own Date cell genuinely
        // cannot be parsed (even after DateParser's format extensions), infer the
        // closest reliable date from the surrounding registration sequence — but ONLY
        // when the nearest valid date immediately before and after agree exactly. See
        // RegistrationDateInferrer for the full, conservative rule; never guessed either
        // way, and always labeled distinctly from a directly-sourced date.
        if ($applicationDate === null) {
            $registrationRow = null;

            foreach ($rows as $row) {
                if ($row->sheetName === 'REGISTRATION') {
                    $registrationRow = $row;

                    break;
                }
            }

            if ($registrationRow !== null) {
                $inference = RegistrationDateInferrer::infer($registrationRow->rowNumber, $registrationRawDatesByRow);

                if ($inference !== null) {
                    $applicationDate = $inference['date'];
                    $applicationDateInferred = true;
                    $rawDateForNote = $registrationRawDatesByRow[$registrationRow->rowNumber] ?? null;
                    $applicationDateInferenceNote = 'REGISTRATION row '.$registrationRow->rowNumber.
                        ' had an unparseable Date cell (raw value: '.($rawDateForNote === null || $rawDateForNote === '' ? '(blank)' : "\"{$rawDateForNote}\"").
                        '). Inferred '.$inference['date']->toDateString().
                        ' because the nearest valid dates on REGISTRATION row '.$inference['beforeRow'].
                        ' (before) and row '.$inference['afterRow'].' (after) both agree exactly.';
                }
            }
        }

        // CONFIRMED business decision (final, applies to EVERYONE — REGISTRATION-matched
        // people whose date genuinely can't be resolved even after neighbor inference,
        // AND new unmatched candidates from Waiting List or wiilasha): a NULL
        // application_date is acceptable and is NEVER by itself a manual-review blocker.
        // Never fabricated either way — it simply stays null. The strict neighbor
        // inference above is still attempted first; this only governs whether failing to
        // resolve it blocks readiness, which it no longer does.

        // waiting_since: only ever sourced from a Waiting-List-family row's own date, kept
        // separate from the registration date even when both exist for the same person.
        $waitingSince = null;

        foreach ($rows as $row) {
            if (! in_array($row->sheetName, self::WAITING_DATE_SHEETS, true)) {
                continue;
            }

            $parsed = DateParser::parse($row->dateRaw);

            if ($parsed !== null) {
                $waitingSince = $parsed;

                break;
            }
        }

        // Training fee: only an exact "paid" is mapped; anything else is preserved raw, not guessed.
        $trainingFeeStatus = null;
        $trainingFeeRawUnmapped = null;
        $otherInfoParts = [];

        if ($secondaryContactPhoneUnreliableNote !== null) {
            $otherInfoParts[] = $secondaryContactPhoneUnreliableNote;
        }

        if ($secondaryContactNameUnreliableNote !== null) {
            $otherInfoParts[] = $secondaryContactNameUnreliableNote;
        }

        foreach ($rows as $row) {
            $note = $row->otherInfo;

            if ($note !== null) {
                $otherInfoParts[] = "[{$row->sheetName}] {$note}";
            }

            $fee = $row->trainingFeeRaw;

            if ($fee === null) {
                continue;
            }

            $sheetConfig = $configRegistry[$row->sheetName] ?? null;

            if ($sheetConfig?->trainingFeeColIsCancellationReason === true) {
                $otherInfoParts[] = "[{$row->sheetName}] Cancellation note: {$fee}";

                continue;
            }

            if ($trainingFeeStatus === null && mb_strtolower(trim($fee)) === 'paid') {
                $trainingFeeStatus = 'paid';
            } elseif ($trainingFeeRawUnmapped === null) {
                $trainingFeeRawUnmapped = $fee;
            }
        }

        if ($jobRaw !== null) {
            $otherInfoParts[] = "Original job title (Excel): {$jobRaw}";
        }

        // Color signals — RED forces cancellation (union with sheet-based cancellation, per the
        // confirmed business rule), YELLOW/GREEN are preserved as context only, never a status override.
        // Every other color and no-fill-at-all are a confirmed "guarantor needed" signal (see
        // RegistrationColorReader) — captured here as $anyDamiinColorSignal, never as a pipelineStage.
        $anyRed = false;
        $anyYellow = false;
        $anyGreen = false;
        $anyDamiinColorSignal = false;
        $cancellationSources = [];
        $cancellationContextParts = [];
        $cancellationReason = null;

        foreach ($rows as $row) {
            $isCancellationSourceRow = false;

            if ($row->sheetName === 'REGISTRATION') {
                $flags = $registrationColors[$row->rowNumber] ?? null;

                if ($flags !== null) {
                    if ($flags['isRed']) {
                        $anyRed = true;
                        $isCancellationSourceRow = true;
                        $cancellationSources[] = "RED fill in REGISTRATION (row {$row->rowNumber})";
                    }

                    if ($flags['isYellow']) {
                        $anyYellow = true;
                    }

                    if ($flags['isGreen']) {
                        $anyGreen = true;
                    }

                    if ($flags['isDamiinNeeded']) {
                        $anyDamiinColorSignal = true;
                    }
                }
            }

            if (in_array($row->sheetName, self::CANCELLED_SHEET_NAMES, true)) {
                $isCancellationSourceRow = true;
                $cancellationSources[] = "CANCELED sheet (row {$row->rowNumber})";
            }

            if (! $isCancellationSourceRow) {
                continue;
            }

            // Traceability only — Stage/Other-Info/the fee-position column are preserved raw
            // and labeled, never asserted as "the reason" (see CancellationReasonExtractor).
            $contextPieces = [];

            if ($row->stageRaw !== null) {
                $contextPieces[] = "Stage: {$row->stageRaw}";
            }

            if ($row->otherInfo !== null) {
                $contextPieces[] = "Other Info: {$row->otherInfo}";
            }

            $sheetConfig = $configRegistry[$row->sheetName] ?? null;

            if ($sheetConfig?->trainingFeeColIsCancellationReason === true && $row->trainingFeeRaw !== null) {
                $contextPieces[] = "Reason/Fee field: {$row->trainingFeeRaw}";

                if ($cancellationReason === null) {
                    $cancellationReason = CancellationReasonExtractor::extract($row->trainingFeeRaw);
                }
            }

            if ($contextPieces !== []) {
                $cancellationContextParts[] = "[{$row->sheetName} row {$row->rowNumber}] ".implode('; ', $contextPieces);
            }
        }

        if ($anyYellow) {
            $otherInfoParts[] = 'REGISTRATION highlight: Damiin ayuu keensaday (already brought a guarantor).';
        }

        if ($anyGreen) {
            $otherInfoParts[] = 'REGISTRATION highlight: Wuu shaqeeyaa ama hore shaqo loo geeyay (works, or was previously sent to work).';
        }

        // Status/pipeline: resolve every row's Stage text via the reviewed dictionary; a RED
        // highlight or CANCELED-sheet membership forces cancellation regardless of Stage text.
        $resolutions = array_map(fn (RawExcelRow $r) => StageDictionary::resolve($r->sheetName, $r->stageRaw), $rows);
        $cancelledByStage = array_any($resolutions, fn (StageResolution $r): bool => $r->isCancellation);
        $needingReview = array_values(array_filter($resolutions, fn (StageResolution $r): bool => $r->needsManualReview));

        $historyNotes = array_map(fn (StageResolution $r): string => $r->sourceNote, $resolutions);
        $wasDefaulted = array_any($resolutions, fn (StageResolution $r): bool => $r->wasDefaulted);

        $isCancellation = $cancelledByStage || $anyRed;

        // CONFIRMED business decision (final): Stage is experience/history/source context,
        // NOT automatically an EmployeeStatus. An unrecognized Stage value is never
        // invented into a lifecycle status, and — just as importantly — never blocks
        // readiness by itself either; the raw text stays preserved via $historyNotes
        // above regardless of whether it resolved to anything.
        if ($isCancellation) {
            $status = EmployeeStatus::Inactive;
            $pipelineStage = null;
        } elseif ($needingReview !== [] && count($needingReview) === count($resolutions)) {
            // every row for this person had an unresolved stage — status/pipelineStage
            // simply stay unresolved (null), never guessed, never a review blocker.
            $status = null;
            $pipelineStage = null;
            $report->ambiguousStageReviewCount++; // informational only — never blocks readiness
        } else {
            $ranked = $this->rankStatuses(array_filter($resolutions, fn (StageResolution $r): bool => ! $r->needsManualReview));
            $status = $ranked?->status;
            $pipelineStage = $ranked?->pipelineStage;
        }

        if ($wasDefaulted) {
            $historyNotes[] = 'Note: stage was blank on at least one source row and was defaulted per the reviewed sheet default.';
        }

        // Confirmed override: green (works, or was previously sent to work) means the
        // CURRENT need is "no training needed", even when a REGISTRATION Stage cell says
        // "need training" — the original Stage text stays preserved via $historyNotes above,
        // this only suppresses the DERIVED current pipeline stage, never the source record.
        if ($anyGreen && $pipelineStage === EmployeePipelineStage::NeedTraining) {
            $pipelineStage = null;
            $report->trainingOverriddenByGreenCount++;
        }

        // Guarantor-need: an INDEPENDENT business flag, never a pipelineStage (see
        // StageDictionary) — combines the REGISTRATION color signal with NEED DAMIIN/
        // DAMIIN WALI KEENIN sheet membership, then applies the confirmed overrides in
        // precedence order: green and yellow (already provided) and Waiting status all
        // mean "no need", regardless of what the raw signal says.
        $anySheetDamiinSignal = array_any($rows, fn (RawExcelRow $r): bool => in_array($r->sheetName, self::DAMIIN_SHEET_NAMES, true));
        $guarantorNeedFlag = $anyDamiinColorSignal || $anySheetDamiinSignal;

        if ($guarantorNeedFlag && $anyGreen) {
            $guarantorNeedFlag = false;
            $report->damiinOverriddenByGreenCount++;
        }

        if ($guarantorNeedFlag && $anyYellow) {
            $guarantorNeedFlag = false;
            $report->damiinOverriddenByYellowCount++;
        }

        if ($guarantorNeedFlag && $status === EmployeeStatus::Waiting) {
            $guarantorNeedFlag = false;
            $report->damiinOverriddenByWaitingCount++;
        }

        $category = CategoryMatcher::match($jobRaw, $existingCategoryNames);

        // CONFIRMED business decision (final): any Job value that still doesn't match a
        // real keyword defaults to "General Cleaning" — the original raw Job text stays
        // fully preserved via $jobRaw/$rawJobTitle regardless, and $category['isConfident']
        // stays false so this low-confidence fallback is always distinguishable in the
        // report from a genuine keyword match. Category is never a manual-review blocker
        // any more, matched or not.
        if ($category['matched'] === null && in_array('General Cleaning', $existingCategoryNames, true)) {
            $category = ['matched' => 'General Cleaning', 'isConfident' => false];
            $report->categoryFallbackToGeneralCleaningCount++;
        }

        // Category-specific sub-classification — see ResolvedPerson::$categorySpecialization.
        // Only ever computed once the top-level category is already confirmed; never
        // invents a specialization for a category that doesn't have one defined.
        $categorySpecialization = match ($category['matched']) {
            'Home Team' => HomeCleaningWorkTypeMatcher::match($jobRaw),
            'Cooking' => CookingSpecializationMatcher::match($jobRaw),
            default => null,
        };

        if ($category['matched'] === 'Home Team') {
            $report->homeCleaningWorkTypeCounts[$categorySpecialization ?? '(unspecified)'] =
                ($report->homeCleaningWorkTypeCounts[$categorySpecialization ?? '(unspecified)'] ?? 0) + 1;
        }

        if ($category['matched'] === 'Cooking') {
            $report->cookingSpecializationCounts[$categorySpecialization ?? '(unspecified)'] =
                ($report->cookingSpecializationCounts[$categorySpecialization ?? '(unspecified)'] ?? 0) + 1;
        }

        $sourceSheets = array_values(array_unique(array_map(fn (RawExcelRow $r): string => $r->sheetName, $rows)));
        $sourceRefs = array_map(fn (RawExcelRow $r): string => "{$r->sheetName}:{$r->rowNumber}", $rows);

        $forcedManualReview = array_any(
            $rows,
            fn (RawExcelRow $r): bool => ($configRegistry[$r->sheetName] ?? null)?->forceManualReview === true,
        );

        if ($forcedManualReview) {
            $reviewReasons[] = 'Source sheet is flagged for manual review (data too thin or too unresolved to auto-import).';
        }

        // Profile completeness is a REPORTING-ONLY dimension here (no --commit exists yet,
        // nothing is written to the live employees/employee_guarantors tables) — it is
        // deliberately separate from $reviewReasons (which blocks readiness) and from
        // $status/$isCancellation. Every Excel-migrated person is incomplete by definition;
        // this list makes WHICH areas are missing visible, never fabricating completion.
        //
        // CONFIRMED: Excel contains NO actual guarantor/Damiin data at all — a secondary
        // contact (Other Contact) is never guarantor documentation, so guarantor record/
        // documentation is ALWAYS reported missing here, regardless of whether a secondary
        // contact was captured. The real guarantor is added later via Employee Profile →
        // Guarantor/Damiin → Add Guarantor.
        $profileIncompleteReasons = [
            'Employee documents not available from Excel migration (no document exists unless separately uploaded).',
        ];

        if ($guarantorNeedFlag) {
            $profileIncompleteReasons[] = 'Guarantor needed per source; no guarantor record exists yet — add via Employee Profile → Guarantor/Damiin → Add Guarantor.';
        } elseif ($anyYellow) {
            $profileIncompleteReasons[] = 'Source indicates a guarantor was already provided ("Damiin ayuu keensaday"), but Excel contains no actual guarantor data — add and verify via Employee Profile → Guarantor/Damiin → Add Guarantor.';
        } else {
            $profileIncompleteReasons[] = 'No guarantor record exists yet — Excel contains no guarantor/Damiin data for anyone.';
        }

        if ($isCancellation) {
            $profileIncompleteReasons[] = 'Cancellation is recorded from Excel source context only, not as a formal HR-reviewed separation record.';
        }

        return new ResolvedPerson(
            normalizedPhone: $normalizedPhone,
            alternatePhone: $this->firstNonNull($rows, fn (RawExcelRow $r) => PhoneNormalizer::normalize($r->phoneRaw)['alternate']),
            fullName: $fullName,
            location: $location,
            age: $age,
            maritalStatus: $maritalStatus,
            livesWith: $livesWith,
            source: $source,
            experience: $experience,
            rawJobTitle: $jobRaw,
            matchedCategory: $category['matched'],
            matchedCategoryIsConfident: $category['isConfident'],
            categorySpecialization: $categorySpecialization,
            isSupervisor: $isSupervisor,
            status: $status,
            pipelineStage: $pipelineStage,
            isCancellation: $isCancellation,
            cancellationSources: $isCancellation ? array_values(array_unique($cancellationSources)) : [],
            cancellationReason: $isCancellation ? $cancellationReason : null,
            cancellationSourceContext: $isCancellation && $cancellationContextParts !== [] ? implode(' | ', $cancellationContextParts) : null,
            applicationDate: $applicationDate,
            applicationDateInferred: $applicationDateInferred,
            applicationDateInferenceNote: $applicationDateInferenceNote,
            waitingSince: $waitingSince,
            trainingFeeStatus: $trainingFeeStatus,
            trainingFeeRawUnmapped: $trainingFeeRawUnmapped,
            secondaryContactPhone: $secondaryContactPhone,
            secondaryContactName: $secondaryContactName,
            otherInfo: $otherInfoParts === [] ? null : implode(' | ', $otherInfoParts),
            historyNote: implode(' ', array_unique($historyNotes)),
            isYellowFlagged: $anyYellow,
            isGreenFlagged: $anyGreen,
            guarantorNeedFlag: $guarantorNeedFlag,
            sourceSheets: $sourceSheets,
            sourceRefs: $sourceRefs,
            isReadyToImport: $reviewReasons === [],
            reviewReasons: $reviewReasons,
            profileIncompleteReasons: $profileIncompleteReasons,
        );
    }

    /**
     * @param  list<StageResolution>  $resolutions
     */
    private function rankStatuses(array $resolutions): ?StageResolution
    {
        $order = [
            EmployeeStatus::Active->value => 6,
            EmployeeStatus::Approved->value => 5,
            EmployeeStatus::Waiting->value => 4,
            EmployeeStatus::Practical->value => 3,
            EmployeeStatus::Recruitment->value => 2,
            EmployeeStatus::Applicant->value => 1,
        ];

        $best = null;
        $bestRank = -1;

        foreach ($resolutions as $resolution) {
            $rank = $order[$resolution->status?->value] ?? 0;

            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $resolution;
            }
        }

        return $best;
    }

    /**
     * @param  list<RawExcelRow>  $rows
     */
    private function firstNonNull(array $rows, callable $extractor): ?string
    {
        foreach ($rows as $row) {
            $value = $extractor($row);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  list<ResolvedPerson>  $knownPeople
     */
    private function handleMogadishuHospital(Spreadsheet $spreadsheet, ExcelMigrationReport $report, array $knownPeople): void
    {
        $sheet = $spreadsheet->getSheetByName('MOGADISHU HOSPITAL');

        if ($sheet === null) {
            return;
        }

        $highestRow = $sheet->getHighestDataRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $name = trim((string) ($sheet->getCell('B'.$row)->getValue() ?? ''));

            if ($name === '') {
                continue;
            }

            $report->mogadishuHospitalRowCount++;

            $matches = [];

            foreach ($knownPeople as $person) {
                if (NameMatcher::isLikelySamePersonByNameAlone($name, $person->fullName)) {
                    $matches[] = $person->fullName.' ('.$person->normalizedPhone.')';
                }
            }

            if ($matches !== []) {
                $report->mogadishuHospitalMatches[] = ['name' => $name, 'phone' => '', 'matchedAgainst' => $matches];
            }
        }
    }

    private function countExcludedSheets(Spreadsheet $spreadsheet, ExcelMigrationReport $report): void
    {
        $report->excludedPayrollRowCount = $this->countNonEmptyRows($spreadsheet, 'Payroll', 2, 'B');
        $report->excludedJobOrdersRowCount = $this->countNonEmptyRows($spreadsheet, 'Job Orders', 2, 'B');
        $report->excludedKormeerRowCount = $this->countNonEmptyRows($spreadsheet, 'KORMEER', 1, 'A');

        // A "0" for these sheets above/elsewhere is ambiguous between "present but empty" and
        // "doesn't exist in this workbook at all" — this makes that explicit rather than silent.
        foreach ([
            'Payroll', 'Job Orders', 'KORMEER', 'MOGADISHU HOSPITAL', 'TABABAR MARIN',
            'Student Practical training', 'Sheet2', 'Other Jobs', 'DAMIIN WALI KEENIN', 'KUWA LA KANSALEY',
        ] as $sheetName) {
            $report->otherSheetPresence[$sheetName] = $spreadsheet->getSheetByName($sheetName) !== null;
        }
    }

    private function countNonEmptyRows(Spreadsheet $spreadsheet, string $sheetName, int $startRow, string $keyColumn): int
    {
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($sheet === null) {
            return 0;
        }

        $highestRow = $sheet->getHighestDataRow();
        $count = 0;

        for ($row = $startRow; $row <= $highestRow; $row++) {
            $value = trim((string) ($sheet->getCell($keyColumn.$row)->getValue() ?? ''));

            if ($value !== '') {
                $count++;
            }
        }

        return $count;
    }
}
