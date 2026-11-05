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
 *
 * PAGED on the server (owner decision, 2026-11-05): the rows of one page are returned with the page's meta, and the totals cover the WHOLE filtered set, so a
 * person can reach every record they are allowed to see and the count they read is the real one. The old silent cut at 2,000 rows is gone. The only limit
 * left is a safety ceiling on how many records of one kind are examined per request (MAX_SCAN); if it is ever reached the answer says so (`meta.truncated`)
 * instead of hiding the rest.
 */
final class DeductionBalanceList
{
    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 200;

    /** Most records of ONE kind examined per request; beyond it the answer is flagged truncated. */
    public const MAX_SCAN = 25000;

    /** The ceiling in force (the constant; a test lowers it to prove the truncation flag). */
    public static int $scanLimit = self::MAX_SCAN;

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>, meta: array<string, mixed>}
     */
    public static function build(?int $companyId = null, ?string $kind = null, bool $leaversOnly = false, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $records = [];
        $truncated = false;

        foreach (['penalty', 'advance'] as $key) {
            if ($kind !== null && $kind !== $key) {
                continue;
            }

            $descriptor = DeductionKind::of($key);
            $found = ($descriptor->model)::query()->active()
                ->with(['employee:id,employee_number,full_name,status', 'clientCompany:id,name'])
                ->when($companyId !== null, fn ($q) => $q->where('client_company_id', $companyId))
                ->orderBy('payroll_period')->orderBy('id')
                ->limit(self::$scanLimit + 1)
                ->get();

            if ($found->count() > self::$scanLimit) {
                $truncated = true;
                $found = $found->take(self::$scanLimit);
            }

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

        // A stable order across pages: oldest registration first, then penalties before advances, then the record's own id.
        usort($rows, fn (array $a, array $b): int => [$a['registered_period'], $a['kind'], $a['id']] <=> [$b['registered_period'], $b['kind'], $b['id']]);

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;

        return [
            'rows' => array_slice($rows, $offset, $perPage),
            // The whole filtered set, never just the page.
            'totals' => [
                'count' => $total,
                'owed_cents' => (int) array_sum(array_column($rows, 'owed_cents')),
                'leaver_count' => count(array_filter($rows, fn (array $r): bool => $r['leaver'])),
                'leaver_owed_cents' => (int) array_sum(array_map(fn (array $r): int => $r['leaver'] ? $r['owed_cents'] : 0, $rows)),
            ],
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, $total),
                // True only when the safety ceiling (MAX_SCAN records of one kind) was reached: some records were NOT examined.
                'truncated' => $truncated,
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
