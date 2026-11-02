<?php

namespace App\Actions\Hr\CompanyEmployees;

use App\Models\AssignmentAbsence;
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

    /** The label of an audit row written by the generic assignment actions (no event tag). */
    private static function genericEvent(AuditLog $log): string
    {
        return match (true) {
            $log->action->value === 'create' => 'assigned',
            $log->action->value === 'cancel' => 'cancelled',
            str_ends_with((string) $log->description, ' ended.') => 'ended',
            str_contains((string) $log->description, 'Salary for work assignment') => 'salary_changed',
            default => 'changed',
        };
    }

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
                    'event' => $meta['event'] ?? self::genericEvent($log),
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
            'shift' => $a->shift?->value,
            'status' => $a->status->value,
            'start_date' => $a->start_date->toDateString(),
            'end_date' => $a->end_date?->toDateString(),
            'replaces_assignment_id' => $a->replaces_assignment_id,
        ])->all();

        return ['events' => $events, 'chain' => $chain, 'cycles' => $this->cycles($assignments->pluck('id')->all())];
    }

    /**
     * The Replacement cycles of these assignments: each absence of an original assignment (which keeps its id across all of them) with the events
     * written for it, in the order they happened.
     *
     * @param  list<int>  $assignmentIds
     * @return list<array<string, mixed>>
     */
    private function cycles(array $assignmentIds): array
    {
        return AssignmentAbsence::query()
            ->with(['events.admin'])
            ->whereIn('work_assignment_id', $assignmentIds)
            ->orderBy('absent_from')
            ->orderBy('id')
            ->get()
            ->map(fn (AssignmentAbsence $absence): array => [
                'id' => $absence->id,
                'assignment_id' => $absence->work_assignment_id,
                'last_working_day' => $absence->last_working_day->toDateString(),
                'absent_from' => $absence->absent_from->toDateString(),
                'returned_on' => $absence->returned_on?->toDateString(),
                'terminated_on' => $absence->terminated_on?->toDateString(),
                'events' => $absence->events->map(fn ($e): array => [
                    'event_type' => $e->event_type,
                    'event_date' => $e->event_date->toDateString(),
                    'replacement_assignment_id' => $e->replacement_assignment_id,
                    'by' => $e->admin?->full_name,
                    'at' => $e->created_at?->toIso8601String(),
                    'details' => $e->details,
                ])->values()->all(),
            ])
            ->all();
    }
}
