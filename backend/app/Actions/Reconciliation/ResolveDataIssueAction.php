<?php

namespace App\Actions\Reconciliation;

use App\Enums\AuditAction;
use App\Enums\DataIssueStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\DataIssue;
use App\Support\Reconciliation\DataIssueAuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * The only path that moves a Data Issue into a closing status (Resolved /
 * Accepted Difference / Cannot Resolve) — records resolved_by/resolved_at.
 * Never automatic; always an explicit human action.
 */
class ResolveDataIssueAction
{
    public function __construct(private DataIssueAuditRecorder $auditRecorder) {}

    /**
     * @param  array{status: string, resolution: string, root_cause?: ?string, notes?: ?string}  $data
     */
    public function handle(DataIssue $issue, array $data, Admin $actor): DataIssue
    {
        return DB::transaction(function () use ($issue, $data, $actor) {
            $before = $issue->only(['status', 'resolution', 'root_cause', 'notes']);

            $issue->status = DataIssueStatus::from($data['status']);
            $issue->resolution = $data['resolution'];
            if (array_key_exists('root_cause', $data)) {
                $issue->root_cause = $data['root_cause'];
            }
            if (array_key_exists('notes', $data)) {
                $issue->notes = $data['notes'];
            }
            $issue->resolved_by = $actor->id;
            $issue->resolved_at = now();
            $issue->save();

            $after = $issue->only(['status', 'resolution', 'root_cause', 'notes']);
            $this->auditRecorder->record($issue, $actor, [
                'status' => [$before['status'], $after['status']],
                'resolution' => [$before['resolution'], $after['resolution']],
                'root_cause' => [$before['root_cause'], $after['root_cause']],
                'notes' => [$before['notes'], $after['notes']],
            ]);

            event(AuditEvent::record(
                action: AuditAction::Approve,
                admin: $actor,
                description: "Data issue '{$issue->title}' marked {$issue->status->label()} ({$issue->issue_number}).",
                entityType: DataIssue::class,
                entityId: $issue->id,
            ));

            return $issue->fresh(['creator', 'resolver', 'affectedRecords.recordable', 'auditLogs.admin']);
        });
    }
}
