<?php

namespace App\Support\Hr\Payroll;

use App\Enums\WorkAssignmentStatus;
use App\Models\EmployeeWorkAssignment;

/**
 * The read-only list of what is still owed on penalties and one-month advances (owner decisions Q6 / Q7, 2026-11-05).
 *
 * Release 1 creates NO debt: an amount payroll could not deduct stays on the record and is deducted from later pay. For a LEAVER - an employee with no
 * open assignment at the record's company any more - nobody is paid any more, so the balance stays here, visible and never forgiven, until it is
 * handled (a Super Admin may waive it; collecting it is a Release 2 matter).
 *
 *  state "pending"          nothing was deducted yet (registered, waiting for a finalized payroll)
 *  state "partly_deducted"  a finalized payroll took part of it and the rest is owed
 */
final class DeductionBalanceList
{
    private const LIMIT = 2000;

    /** @return array{rows: list<array<string, mixed>>, totals: array<string, int>} */
    public static function build(?int $companyId = null, ?string $kind = null, bool $leaversOnly = false): array
    {
        $records = [];

        foreach (['penalty', 'advance'] as $key) {
            if ($kind !== null && $kind !== $key) {
                continue;
            }

            $descriptor = DeductionKind::of($key);
            $found = ($descriptor->model)::query()->active()
                ->with(['employee:id,employee_number,full_name,status', 'clientCompany:id,name'])
                ->when($companyId !== null, fn ($q) => $q->where('client_company_id', $companyId))
                ->orderBy('payroll_period')->orderBy('id')
                ->get();
            $deducted = DeductionBalances::deducted($descriptor->sourceType, $found->pluck('id')->map(fn ($id): int => (int) $id)->all());

            foreach ($found as $record) {
                $taken = $deducted[(int) $record->id] ?? 0;
                $owed = DeductionBalances::owed($record, $taken);

                if ($owed > 0) {
                    $records[] = [$descriptor, $record, $taken, $owed];
                }
            }
        }

        $open = self::openCompanyKeys(array_values(array_unique(array_map(fn (array $r): int => (int) $r[1]->employee_id, $records))));
        $rows = [];

        foreach ($records as [$descriptor, $record, $taken, $owed]) {
            $leaver = ! in_array(InputOwnership::key($record->client_company_id), $open[(int) $record->employee_id] ?? [], true);

            if ($leaversOnly && ! $leaver) {
                continue;
            }

            $rows[] = [
                'kind' => $descriptor->key,
                'id' => (int) $record->id,
                'employee_id' => (int) $record->employee_id,
                'employee_number' => $record->employee?->employee_number,
                'employee_name' => $record->employee?->full_name,
                'employee_status' => $record->employee?->status?->value,
                'client_company_id' => $record->client_company_id,
                'company_name' => $record->clientCompany?->name,
                'registered_period' => $record->payroll_period,
                'reason' => $record->reason,
                'registered_cents' => $record->amountCents(),
                'deducted_cents' => $taken,
                'waived_cents' => (int) $record->waived_cents,
                'owed_cents' => $owed,
                'state' => $taken > 0 ? 'partly_deducted' : 'pending',
                'leaver' => $leaver,
                'version' => (int) $record->version,
            ];
        }

        $rows = array_slice($rows, 0, self::LIMIT);

        return [
            'rows' => $rows,
            'totals' => [
                'count' => count($rows),
                'owed_cents' => (int) array_sum(array_column($rows, 'owed_cents')),
                'leaver_count' => count(array_filter($rows, fn (array $r): bool => $r['leaver'])),
                'leaver_owed_cents' => (int) array_sum(array_map(fn (array $r): int => $r['leaver'] ? $r['owed_cents'] : 0, $rows)),
            ],
        ];
    }

    /**
     * The company keys each employee still works for (an open, not cancelled assignment), so a record whose company is not among them is a leaver's.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, list<string>>
     */
    private static function openCompanyKeys(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $today = now()->toDateString();
        $keys = [];

        foreach (array_chunk($employeeIds, 500) as $chunk) {
            $assignments = EmployeeWorkAssignment::query()
                ->whereIn('employee_id', $chunk)
                ->where('status', '!=', WorkAssignmentStatus::Cancelled)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
                ->get(['id', 'employee_id']);
            $company = PayrollSections::companyIdsByAssignment($assignments->pluck('id')->map(fn ($id): int => (int) $id)->all());

            foreach ($assignments as $assignment) {
                $keys[(int) $assignment->employee_id][] = InputOwnership::key($company[(int) $assignment->id] ?? null);
            }
        }

        return $keys;
    }
}
