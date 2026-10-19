<?php

namespace App\Actions\Hr\CompanyEmployees;

use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\EmployeeWorkAssignment;

/**
 * A readable history of one company's assignments, built from the audit trail (who did what, when, to which assignment) and the
 * assignment chain itself (who replaced whom). Read-only: it never alters anything.
 */
class ListCompanyEmployeeHistoryAction
{
    public const LIMIT = 200;

    /** @return array{events: list<array<string, mixed>>, chain: list<array<string, mixed>>} */
    public function handle(ClientCompany $company, ?int $employeeId = null): array
    {
        $assignments = EmployeeWorkAssignment::query()
            ->with(['employee', 'position'])
            ->where('client_company_id', $company->id)
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $byId = $assignments->keyBy('id');

        $events = AuditLog::query()
            ->with('admin')
            ->where('entity_type', EmployeeWorkAssignment::class)
            ->whereIn('entity_id', $assignments->pluck('id'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (AuditLog $log) use ($byId): array {
                $a = $byId->get($log->entity_id);
                $meta = $log->metadata ?? [];

                return [
                    'at' => $log->created_at?->toIso8601String(),
                    'by' => $log->admin?->full_name,
                    'event' => $meta['event'] ?? ($log->action->value === 'create' ? 'assigned' : ($log->action->value === 'cancel' ? 'cancelled' : 'changed')),
                    'description' => $log->description,
                    'assignment_id' => $log->entity_id,
                    'employee_id' => $a?->employee_id,
                    'employee_number' => $a?->employee?->employee_number,
                    'employee_name' => $a?->employee?->full_name,
                    'details' => array_diff_key($meta, array_flip(['event'])),
                ];
            })
            ->all();

        $chain = $assignments->map(fn (EmployeeWorkAssignment $a): array => [
            'assignment_id' => $a->id,
            'employee_id' => $a->employee_id,
            'employee_number' => $a->employee?->employee_number,
            'employee_name' => $a->employee?->full_name,
            'position_name' => $a->position?->name,
            'assignment_type' => $a->assignment_type?->value ?? 'permanent',
            'status' => $a->status->value,
            'start_date' => $a->start_date->toDateString(),
            'end_date' => $a->end_date?->toDateString(),
            'replaces_assignment_id' => $a->replaces_assignment_id,
        ])->all();

        return ['events' => $events, 'chain' => $chain];
    }
}
