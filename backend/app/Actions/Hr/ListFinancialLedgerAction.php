<?php

namespace App\Actions\Hr;

use App\Models\EmployeeAdvance;
use App\Models\EmployeePayment;
use App\Models\EmployeePenalty;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * docs/HRM_MARKETING_SRS.md HR Phase 6: a combined, read-only PRESENTATION
 * of Payments + Penalties + Advances - the three underlying tables/entities
 * are never merged, only their rows are tagged by type and interleaved by
 * date for one browsable ledger. Each row still traces back to exactly one
 * of the three real tables.
 */
class ListFinancialLedgerAction
{
    public function handle(array $filters): LengthAwarePaginator
    {
        $rows = new Collection();

        if (empty($filters['type']) || $filters['type'] === 'payment') {
            $rows = $rows->merge($this->paymentsQuery($filters)->get()->map(fn (EmployeePayment $p) => [
                'type' => 'payment',
                'id' => $p->id,
                'date' => $p->payment_date?->toDateString(),
                'employee_id' => $p->employee_id,
                'employee_name' => $p->employee?->full_name,
                'department_name' => $p->employee?->department?->name,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'reason' => null,
                'notes' => $p->notes,
            ]));
        }

        if (empty($filters['type']) || $filters['type'] === 'penalty') {
            $rows = $rows->merge($this->penaltiesQuery($filters)->get()->map(fn (EmployeePenalty $p) => [
                'type' => 'penalty',
                'id' => $p->id,
                'date' => $p->penalty_date?->toDateString(),
                'employee_id' => $p->employee_id,
                'employee_name' => $p->employee?->full_name,
                'department_name' => $p->employee?->department?->name,
                'amount' => $p->deduction_amount,
                'currency' => $p->currency,
                'reason' => $p->reason,
                'notes' => $p->notes,
            ]));
        }

        if (empty($filters['type']) || $filters['type'] === 'advance') {
            $rows = $rows->merge($this->advancesQuery($filters)->get()->map(fn (EmployeeAdvance $a) => [
                'type' => 'advance',
                'id' => $a->id,
                'date' => $a->advance_date?->toDateString(),
                'employee_id' => $a->employee_id,
                'employee_name' => $a->employee?->full_name,
                'department_name' => $a->employee?->department?->name,
                'amount' => $a->amount,
                'currency' => $a->currency,
                'reason' => $a->reason,
                'notes' => $a->notes,
            ]));
        }

        $sorted = $rows->sortByDesc('date')->values();

        $perPage = (int) ($filters['per_page'] ?? 15);
        $page = (int) ($filters['page'] ?? 1);

        return new LengthAwarePaginator(
            $sorted->slice(($page - 1) * $perPage, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
        );
    }

    private function applyCommonFilters($query, array $filters, string $dateColumn)
    {
        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $filters['department_id']));
        }

        if (! empty($filters['from'])) {
            $query->where($dateColumn, '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where($dateColumn, '<=', $filters['to']);
        }

        return $query;
    }

    private function paymentsQuery(array $filters)
    {
        return $this->applyCommonFilters(EmployeePayment::query()->with('employee.department'), $filters, 'payment_date');
    }

    private function penaltiesQuery(array $filters)
    {
        return $this->applyCommonFilters(EmployeePenalty::query()->with('employee.department'), $filters, 'penalty_date');
    }

    private function advancesQuery(array $filters)
    {
        return $this->applyCommonFilters(EmployeeAdvance::query()->with('employee.department'), $filters, 'advance_date');
    }
}
