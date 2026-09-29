<?php

namespace App\Actions\Reconciliation;

use App\Enums\AuditAction;
use App\Enums\DataIssueStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\DataIssue;
use App\Support\Reconciliation\DataIssueNumberGenerator;
use Illuminate\Support\Facades\DB;

class CreateDataIssueAction
{
    public function __construct(private DataIssueNumberGenerator $numberGenerator) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, Admin $actor): DataIssue
    {
        return DB::transaction(function () use ($data, $actor) {
            $affectedEmployees = $data['affected_employees'] ?? [];
            unset($data['affected_employees']);

            $issue = DataIssue::query()->create([
                ...$data,
                'issue_number' => $this->numberGenerator->next(),
                'status' => $data['status'] ?? DataIssueStatus::Open->value,
                'created_by' => $actor->id,
            ]);

            // Deduplicated by (employee_id) — never link the same employee twice.
            $seen = [];
            foreach ($affectedEmployees as $link) {
                $employeeId = (int) ($link['employee_id'] ?? 0);

                if ($employeeId === 0 || isset($seen[$employeeId])) {
                    continue;
                }
                $seen[$employeeId] = true;

                $issue->affectedRecords()->create([
                    'recordable_type' => 'employee',
                    'recordable_id' => $employeeId,
                    'context_note' => $link['context_note'] ?? null,
                    'created_at' => now(),
                ]);
            }

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Data issue '{$issue->title}' created ({$issue->issue_number}).",
                entityType: DataIssue::class,
                entityId: $issue->id,
            ));

            return $issue->load(['creator', 'affectedRecords.recordable']);
        });
    }
}
