<?php

namespace App\Support\Hr\Facility;

use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\FacilityInspection;
use App\Models\WorkLocation;
use App\Support\ApiResponse;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The two rules every inspection write shares, plus the writing of its checked items and uniform issues.
 *
 *  - A uniform issue can only name an employee who WORKS for the inspected company on the inspection date (an assignment of that company that
 *    is not cancelled and covers the date). Anyone else is refused with a message naming them; nothing is saved.
 *  - A workplace, when given, must belong to the inspected company.
 *
 * Employee identity is never copied: the issue points at the employee row, so names and ids stay in one place.
 */
final class FacilityInspectionWriter
{
    public function assertWorkplaceBelongsToCompany(?int $workLocationId, int $clientCompanyId): void
    {
        if ($workLocationId === null) {
            return;
        }

        $belongs = WorkLocation::query()->where('id', $workLocationId)->where('client_company_id', $clientCompanyId)->exists();

        if (! $belongs) {
            throw new HttpResponseException(ApiResponse::error('The selected workplace does not belong to this company.', 'WORKPLACE_NOT_OF_COMPANY', 422));
        }
    }

    /** @param  list<array<string, mixed>>  $uniformIssues */
    public function assertEmployeesWorkForCompany(array $uniformIssues, int $clientCompanyId, CarbonInterface $inspectedOn): void
    {
        $ids = array_values(array_unique(array_map(fn (array $i): int => (int) $i['employee_id'], $uniformIssues)));

        if ($ids === []) {
            return;
        }

        $date = $inspectedOn->toDateString();
        $assigned = EmployeeWorkAssignment::query()
            ->whereIn('employee_id', $ids)
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->where('client_company_id', $clientCompanyId)
                ->orWhereHas('workLocation', fn ($w) => $w->where('client_company_id', $clientCompanyId)))
            ->whereDate('start_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date))
            ->pluck('employee_id')
            ->unique()
            ->all();

        $missing = array_values(array_diff($ids, $assigned));

        if ($missing !== []) {
            $names = Employee::withTrashed()->whereIn('id', $missing)->get(['employee_number', 'full_name'])
                ->map(fn (Employee $e): string => "{$e->full_name} ({$e->employee_number})")->implode(', ');

            throw new HttpResponseException(ApiResponse::error(
                "Only employees assigned to this company on the inspection date can be named in a uniform issue. Not assigned here: {$names}.",
                'UNIFORM_EMPLOYEE_NOT_ASSIGNED',
                422,
                ['employee_ids' => $missing],
            ));
        }
    }

    /**
     * Replaces the items and uniform issues of an inspection with the given ones.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $uniformIssues
     */
    public function write(FacilityInspection $inspection, array $items, array $uniformIssues): void
    {
        $inspection->items()->delete();
        $inspection->uniformIssues()->delete();

        foreach ($items as $item) {
            $applicable = (bool) $item['applicable'];
            $inspection->items()->create([
                'item_key' => $item['item_key'],
                'applicable' => $applicable,
                'result' => $applicable ? $item['result'] : null,
                'issue_description' => $applicable && $item['result'] === 'unsatisfactory' ? trim((string) $item['issue_description']) : null,
                'corrective_action' => $applicable && $item['result'] === 'unsatisfactory' ? trim((string) $item['corrective_action']) : null,
            ]);
        }

        foreach ($uniformIssues as $issue) {
            $inspection->uniformIssues()->create([
                'employee_id' => (int) $issue['employee_id'],
                'issue' => trim((string) $issue['issue']),
                'warning_given' => (bool) ($issue['warning_given'] ?? false),
                'reason_not_worn' => isset($issue['reason_not_worn']) && trim((string) $issue['reason_not_worn']) !== '' ? trim((string) $issue['reason_not_worn']) : null,
                'corrective_action' => isset($issue['corrective_action']) && trim((string) $issue['corrective_action']) !== '' ? trim((string) $issue['corrective_action']) : null,
                'follow_up_on' => $issue['follow_up_on'] ?? null,
            ]);
        }
    }

    /** A compact description of what an inspection contains, for the audit trail (never personal data beyond ids). */
    public function summary(FacilityInspection $inspection): array
    {
        $inspection->loadMissing('items', 'uniformIssues');

        return [
            'inspected_on' => $inspection->inspected_on?->toDateString(),
            'next_follow_up_on' => $inspection->next_follow_up_on?->toDateString(),
            'unsatisfactory_items' => $inspection->items->where('result.value', 'unsatisfactory')->map(fn ($i) => $i->item_key->value)->values()->all(),
            'not_applicable_items' => $inspection->items->where('applicable', false)->map(fn ($i) => $i->item_key->value)->values()->all(),
            'uniform_issue_employee_ids' => $inspection->uniformIssues->pluck('employee_id')->all(),
        ];
    }
}
