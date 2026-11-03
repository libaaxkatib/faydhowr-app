<?php

namespace App\Support\Hr\Payroll;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 *  - A month with company sections where SOME companies are approved / finalized and others are not (the run is then draft or reviewed) -> MIXED:
 *    the stored lines of the protected companies and the live estimate of the others, merged in assignment order.
 *
 * Every line says where it comes from: `line_source` (stored | estimate) and `payment_recorded` (a stored line of a FINALIZED company or run: what has
 * been paid on it is a recorded fact), so a reader never has to guess from the month's status.
 *
 * Nothing is written.
 */
class PayrollPeriodView
{
    public const SOURCE_STORED = 'stored_run';

    public const SOURCE_ESTIMATE = 'live_estimate';

    /** Some companies stored (approved / finalized), the others a live estimate. */
    public const SOURCE_MIXED = 'mixed';

    public const LINE_STORED = 'stored';

    public const LINE_ESTIMATE = 'estimate';

    public function __construct(private readonly PayrollRunCalculator $calculator = new PayrollRunCalculator) {}

    /**
     * @param  list<int>|null  $employeeIds  null = everyone
     * @return array{period: string, source: string, is_estimate: bool, run_id: ?int, run_status: ?string, differs_from_run: bool, lines: list<array<string, mixed>>, warnings: list<string>, totals: array<string, int>}
     */
    public function forPeriod(string $period, ?array $employeeIds = null): array
    {
        $run = PayrollRun::query()->where('period', $period)->first();

        if ($run !== null && in_array($run->status, [PayrollRun::STATUS_APPROVED, PayrollRun::STATUS_FINALIZED], true)) {
            $lines = $this->storedLines($run->items()->with('deductions', 'section')->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds === [] ? [0] : $employeeIds)), $run);

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
        $lockedKeys = $run === null ? [] : array_map(fn (?int $id): string => $id === null ? 'none' : (string) $id, PayrollSections::lockedCompanyIds($run));

        if ($lockedKeys === []) {
            $lines = array_map(fn (array $line): array => $line + ['line_source' => self::LINE_ESTIMATE, 'payment_recorded' => false, 'section_id' => null], $result['items']);

            return [
                'period' => $period,
                'source' => self::SOURCE_ESTIMATE,
                'is_estimate' => true,
                'run_id' => $run?->id,
                'run_status' => $run?->status,
                'differs_from_run' => $run !== null && $this->differsFromRun($run, $result['items'], $employeeIds),
                'lines' => $lines,
                'warnings' => $result['warnings'],
                'totals' => $this->totals($lines),
            ];
        }

        // Mixed month: the protected companies come from what is stored, the rest from the live calculation.
        $companyOf = PayrollSections::companyIdsByAssignment(array_values(array_unique(array_column($result['items'], 'work_assignment_id'))));
        $live = array_values(array_filter($result['items'], fn (array $line): bool => ! in_array(($companyOf[$line['work_assignment_id']] ?? null) === null ? 'none' : (string) $companyOf[$line['work_assignment_id']], $lockedKeys, true)));
        $stored = $this->storedLines(
            $run->items()->with('deductions', 'section')
                ->whereIn('section_id', $run->sections()->whereIn('status', PayrollRunSection::LOCKED_STATUSES)->pluck('id')->all() ?: [0])
                ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds === [] ? [0] : $employeeIds)),
            $run,
        );
        $lines = [
            ...$stored,
            ...array_map(fn (array $line): array => $line + ['line_source' => self::LINE_ESTIMATE, 'payment_recorded' => false, 'section_id' => null], $live),
        ];
        usort($lines, fn (array $a, array $b): int => $a['work_assignment_id'] <=> $b['work_assignment_id']);

        return [
            'period' => $period,
            'source' => self::SOURCE_MIXED,
            'is_estimate' => true,
            'run_id' => $run->id,
            'run_status' => $run->status,
            'differs_from_run' => $this->differsFromRun($run, $live, $employeeIds, true),
            'lines' => $lines,
            'warnings' => $result['warnings'],
            'totals' => $this->totals($lines),
        ];
    }

    /**
     * @param  Builder<PayrollItem>|HasMany<PayrollItem>  $query
     * @return list<array<string, mixed>>
     */
    private function storedLines($query, PayrollRun $run): array
    {
        return $query
            ->orderBy('work_assignment_id')
            ->get()
            ->map(fn (PayrollItem $item): array => [
                'employee_id' => $item->employee_id,
                'work_assignment_id' => $item->work_assignment_id,
                'section_id' => $item->section_id,
                'section_status' => PayrollSections::effective($item->section?->status, $run->status),
                'line_source' => self::LINE_STORED,
                // What has been paid on a line is a recorded fact only once its own company (or its whole run) is finalized.
                'payment_recorded' => PayrollSections::effective($item->section?->status, $run->status) === PayrollRunSection::STATUS_FINALIZED,
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
     * @param  bool  $unprotectedOnly  compare only the stored lines that are not in an approved / finalized section (the live lines given are those)
     */
    private function differsFromRun(PayrollRun $run, array $liveLines, ?array $employeeIds, bool $unprotectedOnly = false): bool
    {
        $protected = $unprotectedOnly ? $run->sections()->whereIn('status', PayrollRunSection::LOCKED_STATUSES)->pluck('id')->all() : [];

        $stored = $run->items()
            ->when($protected !== [], fn ($q) => $q->where(fn ($w) => $w->whereNull('section_id')->orWhereNotIn('section_id', $protected)))
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
