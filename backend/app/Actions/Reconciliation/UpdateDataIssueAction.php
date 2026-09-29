<?php

namespace App\Actions\Reconciliation;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\DataIssue;
use App\Support\Reconciliation\DataIssueAuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * General edits only — title, description, module/type/severity, root
 * cause, resolution text, source/notes, status moves between Open and
 * Investigating, and adding/removing affected records. Moving status to a
 * closing value (Resolved/Accepted Difference/Cannot Resolve) is
 * ResolveDataIssueAction's job, enforced by UpdateDataIssueRequest's
 * validation, not here.
 */
class UpdateDataIssueAction
{
    private const array TRACKED_FIELDS = [
        'title', 'description', 'module', 'issue_type', 'severity', 'status',
        'expected_value', 'actual_value', 'difference_value',
        'root_cause', 'resolution', 'source_reference', 'notes',
    ];

    public function __construct(private DataIssueAuditRecorder $auditRecorder) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(DataIssue $issue, array $data, Admin $actor): DataIssue
    {
        return DB::transaction(function () use ($issue, $data, $actor) {
            $addAffected = $data['add_affected_employees'] ?? [];
            $removeAffectedIds = $data['remove_affected_record_ids'] ?? [];
            unset($data['add_affected_employees'], $data['remove_affected_record_ids']);

            $before = $issue->only(self::TRACKED_FIELDS);
            $wasClosing = $issue->status->isClosing();

            $issue->fill($data);

            // Reopening: moving away from a closing status clears the resolution
            // stamp — resolved_at/resolved_by describe when it BECAME resolved,
            // which is no longer true once it's open again.
            if ($wasClosing && array_key_exists('status', $data) && ! $issue->status->isClosing()) {
                $issue->resolved_by = null;
                $issue->resolved_at = null;
            }

            $issue->save();

            $after = $issue->only(self::TRACKED_FIELDS);
            $changes = [];
            foreach (self::TRACKED_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = [$before[$field], $after[$field]];
                }
            }
            $this->auditRecorder->record($issue, $actor, $changes);

            $existingEmployeeIds = $issue->affectedRecords()
                ->where('recordable_type', 'employee')
                ->pluck('recordable_id')
                ->all();

            foreach ($addAffected as $link) {
                $employeeId = (int) ($link['employee_id'] ?? 0);

                if ($employeeId === 0 || in_array($employeeId, $existingEmployeeIds, true)) {
                    continue;
                }

                $issue->affectedRecords()->create([
                    'recordable_type' => 'employee',
                    'recordable_id' => $employeeId,
                    'context_note' => $link['context_note'] ?? null,
                    'created_at' => now(),
                ]);

                $this->auditRecorder->record($issue, $actor, [
                    'affected_records' => [null, "added employee_id={$employeeId}"],
                ]);
            }

            if ($removeAffectedIds !== []) {
                $removed = $issue->affectedRecords()->whereIn('id', $removeAffectedIds)->get();
                $issue->affectedRecords()->whereIn('id', $removeAffectedIds)->delete();

                foreach ($removed as $record) {
                    $this->auditRecorder->record($issue, $actor, [
                        'affected_records' => ["removed recordable_id={$record->recordable_id}", null],
                    ]);
                }
            }

            if ($changes !== [] || $addAffected !== [] || $removeAffectedIds !== []) {
                event(AuditEvent::record(
                    action: AuditAction::Update,
                    admin: $actor,
                    description: "Data issue '{$issue->title}' updated ({$issue->issue_number}).",
                    entityType: DataIssue::class,
                    entityId: $issue->id,
                ));
            }

            return $issue->fresh(['creator', 'resolver', 'affectedRecords.recordable', 'auditLogs.admin']);
        });
    }
}
