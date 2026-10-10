<?php

namespace App\Support\Hr\Payroll;

use App\Models\PayrollItem;
use App\Models\PayrollRun;

/**
 * The single answer to "what does payroll say for this month?", used by the
 * per-employee payroll summary and the payroll rollup so they can never show
 * a different number from the payroll run itself.
 *
 *  - Approved/finalized run  -> the STORED lines of that run (authoritative, not an estimate).
 *  - Anything else (no run, draft, reviewed) -> the same PayrollRunCalculator the run uses,
 *    calculated live and clearly marked as an ESTIMATE. If a draft/reviewed run exists and
 *    no longer matches the live figures, that is flagged (`differs_from_run`) so HR
 *    knows to regenerate it instead of trusting two different numbers.
 *
 * Nothing is written.
 */
class PayrollPeriodView
{
    public const SOURCE_STORED = 'stored_run';

    public const SOURCE_ESTIMATE = 'live_estimate';

    public function __construct(private readonly PayrollRunCalculator $calculator = new PayrollRunCalculator) {}

    /**
     * @param  list<int>|null  $employeeIds  null = everyone
     * @return array{period: string, source: string, is_estimate: bool, run_id: ?int, run_status: ?string, differs_from_run: bool, lines: list<array<string, mixed>>, warnings: list<string>, totals: array<string, int>}
     */
    public function forPeriod(string $period, ?array $employeeIds = null): array
    {
        $run = PayrollRun::query()->where('period', $period)->first();

        if ($run !== null && in_array($run->status, [PayrollRun::STATUS_APPROVED, PayrollRun::STATUS_FINALIZED], true)) {
            $lines = $this->storedLines($run, $employeeIds);

            return [
                'period' => $period,
                'source' => self::SOURCE_STORED,
                'is_estimate' => false,
                'run_id' => $run->id,
                'run_status' => $run->status,
                'differs_from_run' => false,
                'lines' => $lines,
                'warnings' => array_values($run->totals['warnings'] ?? []),
                'totals' => $this->totals($lines),
            ];
        }

        $result = $this->calculator->calculate($period, $employeeIds);

        return [
            'period' => $period,
            'source' => self::SOURCE_ESTIMATE,
            'is_estimate' => true,
            'run_id' => $run?->id,
            'run_status' => $run?->status,
            'differs_from_run' => $run !== null && $this->differsFromRun($run, $result['items'], $employeeIds),
            'lines' => $result['items'],
            'warnings' => $result['warnings'],
            'totals' => $this->totals($result['items']),
        ];
    }

    /**
     * @param  list<int>|null  $employeeIds
     * @return list<array<string, mixed>>
     */
    private function storedLines(PayrollRun $run, ?array $employeeIds): array
    {
        return $run->items()
            ->with('deductions')
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds === [] ? [0] : $employeeIds))
            ->orderBy('work_assignment_id')
            ->get()
            ->map(fn (PayrollItem $item): array => [
                'employee_id' => $item->employee_id,
                'work_assignment_id' => $item->work_assignment_id,
                'employee_name' => $item->employee_name,
                'employee_number' => $item->employee_number,
                'company_name' => $item->company_name,
                'workplace_name' => $item->workplace_name,
                'eligible_days' => $item->eligible_days,
                'days_in_month' => $item->days_in_month,
                'gross_cents' => (int) $item->gross_cents,
                'replacement_deduction_cents' => (int) $item->replacement_deduction_cents,
                'other_deduction_cents' => (int) $item->other_deduction_cents,
                'adjustment_cents' => (int) $item->adjustment_cents,
                'net_cents' => (int) $item->net_cents,
                'deductions' => $item->deductions->map(fn ($d): array => [
                    'kind' => $d->kind,
                    'source_type' => $d->source_type,
                    'source_id' => $d->source_id,
                    'amount_cents' => (int) $d->amount_cents,
                    'description' => $d->description,
                    'detail' => $d->detail ?? [],
                ])->all(),
                'calc_snapshot' => $item->calc_snapshot ?? [],
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $liveLines
     * @param  list<int>|null  $employeeIds
     */
    private function differsFromRun(PayrollRun $run, array $liveLines, ?array $employeeIds): bool
    {
        $stored = $run->items()
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds === [] ? [0] : $employeeIds))
            ->pluck('net_cents', 'work_assignment_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $live = [];
        foreach ($liveLines as $line) {
            $live[$line['work_assignment_id']] = (int) $line['net_cents'];
        }

        ksort($stored);
        ksort($live);

        return $stored !== $live;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, int>
     */
    private function totals(array $lines): array
    {
        $sum = fn (string $key): int => (int) array_sum(array_column($lines, $key));

        return [
            'item_count' => count($lines),
            'gross_cents' => $sum('gross_cents'),
            'replacement_deduction_cents' => $sum('replacement_deduction_cents'),
            'other_deduction_cents' => $sum('other_deduction_cents'),
            'adjustment_cents' => $sum('adjustment_cents'),
            'net_cents' => $sum('net_cents'),
        ];
    }
}
