<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\EmployeeCategory;
use App\Support\Hr\ExcelMigration\ExcelMigrationCommitter;
use App\Support\Hr\ExcelMigration\ExcelMigrationReport;
use App\Support\Hr\ExcelMigration\ExcelMigrationService;
use App\Support\Hr\ExcelMigration\RegistrationColorReader;
use App\Support\Hr\ExcelMigration\ResolvedPerson;
use Illuminate\Console\Command;

/**
 * One-time migration of the historical/live HR Excel workbook into the
 * existing Employee HR module. See docs of the Phase 5 HR migration design
 * for the full mapping/precedence rules this command implements.
 *
 * Defaults to a read-only dry-run report. --commit writes $report->readyToImport
 * only (never $report->manualReview) via ExcelMigrationCommitter, inside a single
 * transaction, after printing the same full report the dry-run prints.
 */
class MigrateExcelHrDataCommand extends Command
{
    protected $signature = 'hr:migrate-from-excel
        {path : Path to the HR Excel workbook (.xlsx)}
        {--dry-run : Read-only report, no database writes (this is the default behavior)}
        {--commit : Actually write $report->readyToImport to the database}
        {--actor= : Admin ID to attribute created_by/changed_by/separated_by to (optional — left null if omitted)}';

    protected $description = 'Migrates the HR Excel workbook into the Employee HR module. Read-only unless --commit is passed.';

    public function handle(ExcelMigrationService $service, RegistrationColorReader $colorReader, ExcelMigrationCommitter $committer): int
    {
        // This is an offline, one-time CLI tool reading a large workbook whose
        // sheets carry phantom formatting ranges far beyond their real data
        // (see the Phase 5 migration design report) — PhpSpreadsheet allocates
        // against the declared range even in read-only mode. Raising the limit
        // here only affects this command's process, never the web application.
        ini_set('memory_limit', '2G');

        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $isCommit = (bool) $this->option('commit');

        $actor = null;
        $actorOption = $this->option('actor');

        if ($actorOption !== null) {
            $actor = Admin::query()->find((int) $actorOption);

            if ($actor === null) {
                $this->error("--actor={$actorOption}: no such Admin id. No database writes occurred.");

                return self::FAILURE;
            }
        }

        if ($isCommit) {
            $this->warn('Running in COMMIT mode — this WILL write to the database.');
        } else {
            $this->info('Running in DRY-RUN mode — no database writes will occur.');
        }

        $this->newLine();

        $spreadsheet = $service->loadWorkbook($path);
        $existingCategoryNames = EmployeeCategory::query()->pluck('name')->all();

        // CONFIRMED business decision: "Supervisor" is a real production category
        // (see employee_categories). This only models it as existing for a dry-run
        // against a database that doesn't have it yet; a no-op once it's really there.
        if (! in_array('Supervisor', $existingCategoryNames, true)) {
            $existingCategoryNames[] = 'Supervisor';
            $this->comment('NOTE: "Supervisor" is modeled as an existing category for this dry-run only — it has not been created in the database yet.');
        }

        $registrationColors = $colorReader->read($path);

        $report = $service->analyze($spreadsheet, $existingCategoryNames, $registrationColors);

        $this->renderReport($report);

        if (! $isCommit) {
            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn("=== COMMIT: writing {$this->pluralCount($report->readyToImport)} to the database ===");

        $result = $committer->commit($report, $actor, $path);

        $this->newLine();
        $this->info('=== Commit result ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Employees created', $result->employeesCreated],
                ['Employee status histories created', $result->statusHistoriesCreated],
                ['Employee separations created (cancellations)', $result->separationsCreated],
                ['Skipped — already imported (matched by phone)', $result->skippedAsAlreadyImported],
            ],
        );

        if ($result->skippedDuplicatePhones !== []) {
            $this->line('  Skipped phones: '.implode(', ', array_slice($result->skippedDuplicatePhones, 0, 30)));
        }

        return self::SUCCESS;
    }

    private function pluralCount(array $readyToImport): string
    {
        $count = count($readyToImport);

        return $count.' '.($count === 1 ? 'person' : 'people');
    }

    private function renderReport(ExcelMigrationReport $report): void
    {
        $summary = $report->toArray();

        $this->info('=== Summary ===');
        $this->table(
            ['Metric', 'Count'],
            array_map(fn (string $key, mixed $value): array => [
                $key, is_array($value) ? count($value) : $value,
            ], array_keys($summary), $summary),
        );

        if ($report->sheetSummary !== []) {
            $this->newLine();
            $this->info('=== Sheet summary (per employee-source sheet) ===');
            $this->table(
                ['Sheet', 'Rows', 'Matched to REGISTRATION', 'New candidates', 'Manual review', 'Same-sheet duplicate rows'],
                array_map(fn (string $sheet, array $s): array => [
                    $sheet,
                    $s['rows'],
                    $sheet === 'REGISTRATION' ? '—' : $s['matchedToRegistration'],
                    $sheet === 'REGISTRATION' ? '—' : $s['newCandidates'],
                    $s['manualReview'],
                    $s['sameSheetDuplicateRows'],
                ], array_keys($report->sheetSummary), $report->sheetSummary),
            );
        }

        if ($report->cancellationReconciliation !== null) {
            $this->newLine();
            $this->info('=== Cancellation reconciliation (person-level, per-row) ===');
            $r = $report->cancellationReconciliation;
            $this->table(
                ['Metric', 'Count'],
                [
                    ['RED REGISTRATION rows — total', $r->redRowsTotal],
                    ['  A: valid unique cancelled person', $r->redClassA],
                    ['  B: duplicate of another row → same person', $r->redClassB],
                    ['  C: blank/no name — not a person', $r->redClassC],
                    ['  D: phone conflict — manual review', $r->redClassD],
                    ['  E: no valid phone, still resolved', $r->redClassE],
                    ['CANCELED sheet rows — total', $r->canceledRowsTotal],
                    ['  A: valid unique cancelled person', $r->canceledClassA],
                    ['  B: duplicate of another row → same person', $r->canceledClassB],
                    ['  C: blank/no name — not a person', $r->canceledClassC],
                    ['  D: phone conflict — manual review', $r->canceledClassD],
                    ['  E: no valid phone, still resolved', $r->canceledClassE],
                    ['Overlap: confirmed by BOTH sources (one person)', $r->overlapPersons],
                    ['FINAL unique cancelled people', $r->finalUniqueCancelledCount()],
                ],
            );
        }

        $this->newLine();
        $this->info('=== Color-rule reconciliation (REGISTRATION) ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['RED (cancelled)', $report->registrationRedRowCount],
                ['YELLOW (guarantor already provided)', $report->yellowRowCount],
                ['GREEN — total (works / previously sent to work)', $report->greenTotalRowCount],
                ...array_map(fn (string $shade, int $count): array => ["  shade {$shade}", $count], array_keys($report->greenShadeCounts), $report->greenShadeCounts),
                ['OTHER COLOR (guarantor needed)', $report->registrationOtherColorRowCount],
                ['NO FILL (guarantor needed)', $report->registrationNoFillRowCount],
            ],
        );

        $this->newLine();
        $this->info('=== Guarantor-rule reconciliation (need vs. provided — NEVER an actual record) ===');
        $this->line('  CONFIRMED: Excel contains no guarantor/Damiin data for anyone. "Other Contact" is the');
        $this->line('  employee\'s own secondary/emergency contact, never the guarantor — see the separate');
        $this->line('  "Secondary contact" count below. The actual guarantor is always added later via');
        $this->line('  Employee Profile -> Guarantor/Damiin -> Add Guarantor.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['guarantor_need = YES (final, after overrides)', $report->guarantorNeedCount],
                ['guarantor already provided (YELLOW — source context only)', $report->guarantorProvidedCount],
                ['guarantor-need overridden to NO by GREEN', $report->damiinOverriddenByGreenCount],
                ['guarantor-need overridden to NO by YELLOW', $report->damiinOverriddenByYellowCount],
                ['guarantor-need overridden to NO by WAITING status', $report->damiinOverriddenByWaitingCount],
                ['"need training" suppressed by GREEN override', $report->trainingOverriddenByGreenCount],
            ],
        );

        $this->newLine();
        $this->info('=== Secondary contact coverage (NOT the guarantor — see above) ===');
        $this->line("  Secondary/emergency contact captured (reliable phone or name): {$report->secondaryContactCapturedCount}");

        $this->newLine();
        $this->info('=== Profile completeness coverage ===');
        $this->line("  Profile Incomplete: {$report->profileIncompleteCount} of ".(count($report->readyToImport) + count($report->manualReview)).' migrated people (expected: all of them — Excel alone never satisfies full HR documentation, including guarantor documentation).');

        if ($report->matchedCategoryCounts !== []) {
            $this->newLine();
            $this->info('=== Category breakdown (final matched category, including the General Cleaning catch-all) ===');
            arsort($report->matchedCategoryCounts);
            $this->table(
                ['Category', 'People'],
                array_map(fn (string $cat, int $count): array => [$cat, $count], array_keys($report->matchedCategoryCounts), $report->matchedCategoryCounts),
            );
            $this->line("  ...of which {$report->categoryFallbackToGeneralCleaningCount} were the low-confidence General Cleaning catch-all (no real keyword matched).");
        }

        if ($report->homeCleaningWorkTypeCounts !== []) {
            $this->newLine();
            $this->info('=== Home Cleaning Work Type breakdown (Category = Home Team) ===');
            $this->table(
                ['Work Type', 'People'],
                array_map(fn (string $type, int $count): array => [$type, $count], array_keys($report->homeCleaningWorkTypeCounts), $report->homeCleaningWorkTypeCounts),
            );
        }

        if ($report->cookingSpecializationCounts !== []) {
            $this->newLine();
            $this->info('=== Cooking Specialization breakdown (Category = Cooking) ===');
            $this->table(
                ['Specialization', 'People'],
                array_map(fn (string $spec, int $count): array => [$spec, $count], array_keys($report->cookingSpecializationCounts), $report->cookingSpecializationCounts),
            );
        }

        if ($report->unmatchedCategoryValues !== []) {
            $this->newLine();
            $this->info('=== Unmatched Job/Category values (no existing category matched) ===');
            arsort($report->unmatchedCategoryValues);
            $this->table(
                ['Raw job text', 'Occurrences'],
                array_map(fn (string $job, int $count): array => [$job, $count], array_keys($report->unmatchedCategoryValues), $report->unmatchedCategoryValues),
            );
        }

        if ($report->phoneConflicts !== []) {
            $this->newLine();
            $this->info('=== Phone conflicts (manual review — NOT merged) ===');

            foreach (array_slice($report->phoneConflicts, 0, 20) as $conflict) {
                $entries = implode('; ', array_map(
                    fn (array $e): string => "{$e['name']} ({$e['sheet']}:{$e['row']})",
                    $conflict->entries,
                ));
                $this->line("  {$conflict->normalizedPhone}: {$entries}");
            }

            if (count($report->phoneConflicts) > 20) {
                $remaining = count($report->phoneConflicts) - 20;
                $this->line("  ... and {$remaining} more");
            }
        }

        if ($report->registrationDateInferredSamples !== []) {
            $this->newLine();
            $this->info('=== REGISTRATION dates inferred from surrounding rows (audit trail) ===');

            foreach ($report->registrationDateInferredSamples as $person) {
                $this->line("  {$person->fullName} ({$person->normalizedPhone}) -> {$person->applicationDate?->toDateString()}");
                $this->line("    {$person->applicationDateInferenceNote}");
            }
        }

        if ($report->waitingPhoneRecoveredSamples !== []) {
            $this->newLine();
            $this->info('=== Waiting List phones recovered via REGISTRATION name match (no duplicate Employee) ===');

            foreach (array_slice($report->waitingPhoneRecoveredSamples, 0, 30) as $sample) {
                $this->line("  {$sample['name']} ({$sample['sourceRef']}) -> {$sample['recoveredPhone']}");
            }

            if (count($report->waitingPhoneRecoveredSamples) > 30) {
                $remaining = count($report->waitingPhoneRecoveredSamples) - 30;
                $this->line("  ... and {$remaining} more");
            }
        }

        if ($report->waitingNameMatchAmbiguous !== []) {
            $this->newLine();
            $this->info('=== Waiting List no-phone rows with MULTIPLE plausible REGISTRATION matches (manual review — never guessed) ===');

            foreach ($report->waitingNameMatchAmbiguous as $case) {
                $candidateText = implode('; ', array_map(
                    fn (array $c): string => "{$c['phone']} (".implode('/', $c['names']).')',
                    $case['candidates'],
                ));
                $this->line("  \"{$case['rawName']}\" ({$case['sourceRef']}) candidates: {$candidateText}");
            }
        }

        if ($report->otherSheetPresence !== []) {
            $this->newLine();
            $this->info('=== Non-employee-source sheets (never become Employee records) ===');
            $this->table(
                ['Sheet', 'Present in this workbook?', 'Non-empty rows counted'],
                [
                    ['Payroll', $report->otherSheetPresence['Payroll'] ? 'yes' : 'no', $report->excludedPayrollRowCount],
                    ['Job Orders', $report->otherSheetPresence['Job Orders'] ? 'yes' : 'no', $report->excludedJobOrdersRowCount],
                    ['KORMEER', $report->otherSheetPresence['KORMEER'] ? 'yes' : 'no', $report->excludedKormeerRowCount],
                    ['MOGADISHU HOSPITAL', $report->otherSheetPresence['MOGADISHU HOSPITAL'] ? 'yes' : 'no', $report->mogadishuHospitalRowCount],
                    ['TABABAR MARIN', $report->otherSheetPresence['TABABAR MARIN'] ? 'yes' : 'no', '(employee source when present — see sheet summary)'],
                    ['Student Practical training', $report->otherSheetPresence['Student Practical training'] ? 'yes' : 'no', $report->studentPracticalRowCount],
                    ['Sheet2', $report->otherSheetPresence['Sheet2'] ? 'yes' : 'no', '(employee source when present — see sheet summary)'],
                    ['Other Jobs', $report->otherSheetPresence['Other Jobs'] ? 'yes' : 'no', '(employee source when present — see sheet summary)'],
                    ['DAMIIN WALI KEENIN (legacy NEED DAMIIN name)', $report->otherSheetPresence['DAMIIN WALI KEENIN'] ? 'yes' : 'no', '(employee source when present — see sheet summary)'],
                    ['KUWA LA KANSALEY (legacy CANCELED name)', $report->otherSheetPresence['KUWA LA KANSALEY'] ? 'yes' : 'no', '(employee source when present — see sheet summary)'],
                ],
            );
        }

        if ($report->somaliTermsRequiringClarification !== []) {
            $this->newLine();
            $this->info('=== Somali terms requiring user clarification ===');

            foreach ($report->somaliTermsRequiringClarification as $term) {
                $this->line("  \"{$term['term']}\" (column: {$term['column']}, occurrences: {$term['occurrences']}) — example: {$term['example']}");

                if ($term['possibleInterpretation'] !== null) {
                    $this->line("    possible interpretation: {$term['possibleInterpretation']} — NOT applied, uncertain");
                }
            }
        }

        $this->newLine();
        $this->info('=== wiilasha relationship to REGISTRATION ===');
        $this->line("  Total: {$report->wiilashaTotalCount}");
        $this->line("  Matched to REGISTRATION (same person, one Employee): {$report->wiilashaMatchedCount}");
        $this->line("  NOT found in REGISTRATION (new registration candidates): {$report->wiilashaNewCandidateCount}");
        $this->line("  Uncertain (phone conflicts): {$report->wiilashaUncertainCount}");

        if ($report->wiilashaNewCandidateSamples !== []) {
            $this->line('  Sample new candidates:');

            foreach (array_slice($report->wiilashaNewCandidateSamples, 0, 10) as $person) {
                $date = $person->applicationDate?->toDateString() ?? '(none)';
                $this->line("    {$person->fullName} ({$person->normalizedPhone}) — date={$date} job={$person->rawJobTitle} location={$person->location}");
            }
        }

        $this->newLine();
        $this->info('=== Waiting List relationship to REGISTRATION ===');
        $this->line("  Total: {$report->waitingListTotalCount}");
        $this->line("  Matched to REGISTRATION: {$report->waitingListMatchedCount}");
        $this->line("  Unmatched (waiting-list-only): {$report->waitingListUnmatchedCount}");
        $this->line("  Registration Date recovered: {$report->registrationDateRecoveredForWaitingCount}");
        $this->line("  Waiting Date recovered: {$report->waitingDateRecoveredCount}");

        if ($report->mogadishuHospitalMatches !== []) {
            $this->newLine();
            $this->info('=== MOGADISHU HOSPITAL possible matches (no employees created) ===');

            foreach (array_slice($report->mogadishuHospitalMatches, 0, 20) as $match) {
                $this->line("  {$match['name']} -> ".implode(', ', $match['matchedAgainst']));
            }
        }

        $this->newLine();
        $this->info('=== Sample: ready to import (first 10) ===');
        $this->renderPersonSamples(array_slice($report->readyToImport, 0, 10));

        $this->newLine();
        $this->info('=== Sample: manual review (first 15) ===');
        $this->renderPersonSamples(array_slice($report->manualReview, 0, 15), withReasons: true);
    }

    /**
     * @param  list<ResolvedPerson>  $people
     */
    private function renderPersonSamples(array $people, bool $withReasons = false): void
    {
        if ($people === []) {
            $this->line('  (none)');

            return;
        }

        foreach ($people as $person) {
            $status = $person->status?->value ?? 'unresolved';
            $stage = $person->pipelineStage?->value ?? '-';
            $line = "  {$person->fullName} ({$person->normalizedPhone}) — status={$status} stage={$stage} sheets=".implode(',', $person->sourceSheets);

            if ($withReasons && $person->reviewReasons !== []) {
                $line .= ' | reasons: '.implode('; ', $person->reviewReasons);
            }

            $this->line($line);
        }
    }
}
