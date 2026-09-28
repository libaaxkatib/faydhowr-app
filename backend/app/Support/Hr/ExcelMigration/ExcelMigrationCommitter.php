<?php

namespace App\Support\Hr\ExcelMigration;

use App\Enums\AuditAction;
use App\Enums\EmployeeSeparationReason;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeSeparation;
use App\Models\EmployeeStatusHistory;
use App\Support\Hr\EmployeeCodeGenerator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes ExcelMigrationReport::$readyToImport to real Employee (+
 * EmployeeSeparation for a Cancelled person, + one EmployeeStatusHistory
 * row) records. Never touches $report->manualReview — those people are
 * never written, by construction (only $readyToImport is iterated).
 *
 * Deliberately not CreateEmployeeAction: that action hardcodes
 * status=Applicant/pipeline_stage=DamiinNeeded for the normal one-person
 * registration flow, which would silently overwrite every resolved
 * status/pipeline_stage this migration already worked out per person.
 *
 * Idempotent by phone only: re-running against a workbook that has already
 * been committed skips anyone whose normalizedPhone already matches a real
 * Employee row. A person with no reliable phone (normalizedPhone starts
 * with "no-phone:") has no such key and is always (re-)created — this
 * mirrors the same "no-phone" identity limitation the dry-run analysis
 * already has (see ExcelMigrationService::groupByPhone) rather than
 * inventing a new one here.
 */
final class ExcelMigrationCommitter
{
    public function __construct(private EmployeeCodeGenerator $codeGenerator) {}

    public function commit(ExcelMigrationReport $report, ?Admin $actor, string $sourceFile): ExcelMigrationCommitResult
    {
        $categoryIdsByName = EmployeeCategory::query()->pluck('id', 'name')->all();

        $employeesCreated = 0;
        $separationsCreated = 0;
        $statusHistoriesCreated = 0;
        $skippedAsAlreadyImported = 0;
        $skippedDuplicatePhones = [];

        DB::transaction(function () use (
            $report,
            $actor,
            $sourceFile,
            $categoryIdsByName,
            &$employeesCreated,
            &$separationsCreated,
            &$statusHistoriesCreated,
            &$skippedAsAlreadyImported,
            &$skippedDuplicatePhones,
        ): void {
            foreach ($report->readyToImport as $person) {
                $phone = $this->realPhoneOrNull($person->normalizedPhone);

                if ($phone !== null && Employee::query()->withTrashed()->where('phone', $phone)->exists()) {
                    $skippedAsAlreadyImported++;
                    $skippedDuplicatePhones[] = $phone;

                    continue;
                }

                if ($person->matchedCategory === null || ! isset($categoryIdsByName[$person->matchedCategory])) {
                    throw new RuntimeException(
                        "Ready-to-import person '{$person->fullName}' ({$person->normalizedPhone}) has no ".
                        'resolvable employee_category_id — matchedCategory='.
                        var_export($person->matchedCategory, true).
                        '. This indicates the category list used for analyze() no longer matches the '.
                        'database at commit time; aborting the whole commit rather than writing a wrong category.',
                    );
                }

                // CONFIRMED business decision (ExcelMigrationService::resolveGroup): an
                // unrecognized/blank Stage never blocks readiness and status legitimately
                // stays unresolved (null) rather than being guessed from Stage text. Applicant
                // is the enum's own "nothing else known yet" starting state — not a guess at
                // what Stage meant, just the documented default for "no signal".
                $status = $person->status ?? EmployeeStatus::Applicant;

                $employee = Employee::query()->create([
                    'employee_number' => $this->codeGenerator->next(),
                    'full_name' => $person->fullName,
                    'phone' => $phone,
                    'alternate_phone' => $person->alternatePhone,
                    'location' => $person->location,
                    'age' => $person->age,
                    'marital_status' => $person->maritalStatus,
                    'lives_with' => $person->livesWith,
                    'employee_category_id' => $categoryIdsByName[$person->matchedCategory],
                    'category_specialization' => $person->categorySpecialization,
                    'status' => $status,
                    'pipeline_stage' => $person->pipelineStage,
                    'guarantor_needed' => $person->guarantorNeedFlag,
                    'waiting_since' => $person->waitingSince,
                    'is_supervisor' => $person->isSupervisor,
                    'application_date' => $person->applicationDate,
                    'experience' => $person->experience,
                    'training_fee_status' => $person->trainingFeeStatus,
                    'source' => $person->source,
                    'notes' => $this->buildEmployeeNotes($person, $sourceFile),
                    'profile_complete' => false,
                    'created_by' => $actor?->id,
                ]);
                $employeesCreated++;

                EmployeeStatusHistory::query()->create([
                    'employee_id' => $employee->id,
                    'from_status' => null,
                    'to_status' => $status,
                    'changed_by' => $actor?->id,
                    'note' => 'Migrated from Excel HR workbook ('.implode(', ', $person->sourceRefs).').',
                ]);
                $statusHistoriesCreated++;

                if ($person->isCancellation) {
                    EmployeeSeparation::query()->create([
                        'employee_id' => $employee->id,
                        'reason' => EmployeeSeparationReason::Other,
                        // Never fabricated: Excel gives no reliable "cancelled on" date for
                        // most of these people, and application_date is a different fact
                        // (when they registered, not when they were cancelled) — see
                        // the 2026_09_28_100000 migration that made this column nullable.
                        'separation_date' => null,
                        'notes' => $this->buildSeparationNotes($person),
                        'separated_by' => $actor?->id,
                    ]);
                    $separationsCreated++;
                }
            }
        });

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $actor,
            description: "Bulk-imported {$employeesCreated} employees from Excel HR migration ({$sourceFile}), ".
                "including {$separationsCreated} cancellations. {$skippedAsAlreadyImported} already-imported ".
                'people (matched by phone) were skipped.',
            entityType: Employee::class,
            entityId: null,
            metadata: [
                'source_file' => $sourceFile,
                'employees_created' => $employeesCreated,
                'separations_created' => $separationsCreated,
                'skipped_as_already_imported' => $skippedAsAlreadyImported,
            ],
        ));

        return new ExcelMigrationCommitResult(
            employeesCreated: $employeesCreated,
            separationsCreated: $separationsCreated,
            statusHistoriesCreated: $statusHistoriesCreated,
            skippedAsAlreadyImported: $skippedAsAlreadyImported,
            skippedDuplicatePhones: $skippedDuplicatePhones,
        );
    }

    private function realPhoneOrNull(string $normalizedPhone): ?string
    {
        return str_starts_with($normalizedPhone, 'no-phone:') ? null : $normalizedPhone;
    }

    private function buildEmployeeNotes(ResolvedPerson $person, string $sourceFile): string
    {
        $parts = [
            'Migrated from Excel HR workbook ('.basename($sourceFile).'), sheets: '.implode(', ', $person->sourceSheets).'.',
        ];

        if ($person->historyNote !== '') {
            $parts[] = $person->historyNote;
        }

        if ($person->otherInfo !== null) {
            $parts[] = "Other info: {$person->otherInfo}";
        }

        if ($person->secondaryContactName !== null || $person->secondaryContactPhone !== null) {
            // CONFIRMED: this is the employee's own secondary/emergency contact, never the
            // guarantor — see ResolvedPerson::$secondaryContactPhone. No dedicated column
            // exists for it, so it is preserved here rather than discarded.
            $parts[] = 'Secondary/emergency contact: '.
                trim(($person->secondaryContactName ?? '').' '.($person->secondaryContactPhone ?? ''));
        }

        if ($person->applicationDateInferred && $person->applicationDateInferenceNote !== null) {
            $parts[] = "Application date inferred: {$person->applicationDateInferenceNote}";
        }

        if ($person->profileIncompleteReasons !== []) {
            $parts[] = 'Profile incomplete: '.implode('; ', $person->profileIncompleteReasons);
        }

        return implode(' | ', $parts);
    }

    private function buildSeparationNotes(ResolvedPerson $person): string
    {
        $parts = [
            'Migrated cancellation from Excel HR workbook ('.implode(', ', $person->cancellationSources).').',
        ];

        if ($person->cancellationReason !== null) {
            $parts[] = "Reason: {$person->cancellationReason}";
        }

        if ($person->cancellationSourceContext !== null) {
            $parts[] = "Source context: {$person->cancellationSourceContext}";
        }

        return implode(' | ', $parts);
    }
}
