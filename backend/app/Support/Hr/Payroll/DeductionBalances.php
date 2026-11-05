<?php

namespace App\Support\Hr\Payroll;

use App\Models\EmployeeAdvance;
use App\Models\EmployeePenalty;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What is still OWED on every penalty and one-month advance (owner decisions Q6 / Q7, 2026-11-05).
 *
 * A penalty or an advance is registered for one month. Payroll deducts it from that month's net, but never more than the net holds (the net is never
 * below $0); what could not be deducted is NOT lost and NOT forgiven - it stays owed and is deducted from the next months' pay until it is zero. A person
 * who has left keeps the balance visible (no debt is created in Release 1, nothing is waived by itself).
 *
 * The balance is not a stored counter. It is derived, every time, from the payroll lines that were really FINALIZED (or approved, the older state):
 *
 *     owed = registered amount - waived by a Super Admin - cents deducted in finalized / approved company payrolls of OTHER months
 *
 * so there is nothing to "unapply": when a company's payroll is reopened its lines stop counting at once and the amount is owed again; a deduction can
 * never be counted twice; and the total deducted over all finalized months can never exceed the registered amount (a month that is regenerated sees what
 * the other finalized months already took). Draft and reviewed lines are provisional and never count.
 */
final class DeductionBalances
{
    public const TYPE_PENALTY = 'employee_penalty';

    public const TYPE_ADVANCE = 'employee_advance';

    /** Company-payroll states whose stored lines count as really deducted. */
    public const LOCKED = ['finalized', 'approved'];

    private const CHUNK = 500;

    /**
     * Cents already deducted by finalized / approved payrolls, per source id.
     *
     * @param  list<int>  $ids
     * @param  string|null  $exceptPeriod  a month to leave out (the one being calculated or checked)
     * @return array<int, int>
     */
    public static function deducted(string $sourceType, array $ids, ?string $exceptPeriod = null): array
    {
        $out = [];

        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            $rows = DB::table('payroll_item_deductions as d')
                ->join('payroll_items as i', 'i.id', '=', 'd.payroll_item_id')
                ->join('payroll_runs as r', 'r.id', '=', 'i.payroll_run_id')
                ->leftJoin('payroll_run_sections as s', 's.id', '=', 'i.section_id')
                ->where('d.source_type', $sourceType)
                ->whereIn('d.source_id', $chunk)
                ->when($exceptPeriod !== null, fn ($q) => $q->where('r.period', '<>', $exceptPeriod))
                ->where(function ($q): void {
                    $q->where(fn ($w) => $w->whereNotNull('i.section_id')->whereIn('s.status', self::LOCKED))
                        ->orWhere(fn ($w) => $w->whereNull('i.section_id')->whereIn('r.status', self::LOCKED));
                })
                ->groupBy('d.source_id')
                ->selectRaw('d.source_id as source_id, sum(d.amount_cents) as cents')
                ->get();

            foreach ($rows as $row) {
                $out[(int) $row->source_id] = (int) $row->cents;
            }
        }

        return $out;
    }

    /**
     * Puts `deducted_cents` and `owed_cents` on each record of a list (two queries in all), for the employee's penalty / advance cards.
     *
     * @template T of EmployeePenalty|EmployeeAdvance
     *
     * @param  Collection<int, T>  $records
     * @return Collection<int, T>
     */
    public static function annotate(Collection $records, string $sourceType): Collection
    {
        $deducted = self::deducted($sourceType, $records->pluck('id')->map(fn ($id): int => (int) $id)->all());

        foreach ($records as $record) {
            $taken = $deducted[(int) $record->id] ?? 0;
            $record->setAttribute('deducted_cents', $taken);
            $record->setAttribute('owed_cents', $record->status === 'active' ? self::owed($record, $taken) : 0);
        }

        return $records;
    }

    /** What is still owed on one record, given what the finalized payrolls took. */
    public static function owed(EmployeePenalty|EmployeeAdvance $source, int $deducted): int
    {
        return max(0, $source->amountCents() - (int) $source->waived_cents - $deducted);
    }

    /**
     * The penalties and advances of these employees that payroll of $period may still deduct: active, registered for $period or an EARLIER month, with
     * something still owed. Each carries `owed_cents` and `deducted_cents` (taken by finalized payrolls of other months). Oldest first.
     *
     * @param  list<int>  $employeeIds
     * @return array{penalties: array<int, Collection<int, EmployeePenalty>>, advances: array<int, Collection<int, EmployeeAdvance>>}
     */
    public static function owedFor(array $employeeIds, string $period): array
    {
        $result = ['penalties' => [], 'advances' => []];

        foreach (array_chunk(array_values(array_unique($employeeIds)), self::CHUNK) as $chunk) {
            $penalties = EmployeePenalty::query()->active()->whereIn('employee_id', $chunk)->where('payroll_period', '<=', $period)->orderBy('payroll_period')->orderBy('id')->get();
            $advances = EmployeeAdvance::query()->active()->whereIn('employee_id', $chunk)->where('payroll_period', '<=', $period)->orderBy('payroll_period')->orderBy('id')->get();

            $penaltyDeducted = self::deducted(self::TYPE_PENALTY, $penalties->pluck('id')->map(fn ($id): int => (int) $id)->all(), $period);
            $advanceDeducted = self::deducted(self::TYPE_ADVANCE, $advances->pluck('id')->map(fn ($id): int => (int) $id)->all(), $period);

            foreach ($penalties as $penalty) {
                $taken = $penaltyDeducted[(int) $penalty->id] ?? 0;
                $owed = self::owed($penalty, $taken);

                if ($owed > 0) {
                    $penalty->setAttribute('owed_cents', $owed);
                    $penalty->setAttribute('deducted_cents', $taken);
                    $result['penalties'][(int) $penalty->employee_id] = ($result['penalties'][(int) $penalty->employee_id] ?? new Collection)->push($penalty);
                }
            }

            foreach ($advances as $advance) {
                $taken = $advanceDeducted[(int) $advance->id] ?? 0;
                $owed = self::owed($advance, $taken);

                if ($owed > 0) {
                    $advance->setAttribute('owed_cents', $owed);
                    $advance->setAttribute('deducted_cents', $taken);
                    $result['advances'][(int) $advance->employee_id] = ($result['advances'][(int) $advance->employee_id] ?? new Collection)->push($advance);
                }
            }
        }

        return $result;
    }
}
