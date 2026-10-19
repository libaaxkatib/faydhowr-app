<?php

namespace App\Support\Hr\Payroll;

use App\Models\PayrollItem;
use App\Models\PayrollItemDeduction;
use App\Models\PayrollRun;

/**
 * Turns a STORED payroll run into the sheet rows used by the Excel exporter.
 * Nothing is recalculated: every figure comes from the saved items, their
 * itemized deductions and the calculation snapshot, so an export always
 * matches what was reviewed and approved.
 */
class PayrollRunSheetMapper
{
    private const KIND_LABELS = [
        PayrollItemDeduction::KIND_PENALTY => 'Penalty',
        PayrollItemDeduction::KIND_ADVANCE => 'Salary advance',
        PayrollItemDeduction::KIND_ABSENCE => 'Absence',
        PayrollItemDeduction::KIND_UNPAID_LEAVE => 'Unpaid leave',
        PayrollItemDeduction::KIND_OVERPAYMENT_RECOVERY => 'Overpayment recovery',
    ];

    /**
     * @return array{period: string, rows: list<array<string, mixed>>, totals: array<string, int>, reconciliation: array{children_approved_cents: int, parent_deductions_cents: int, ok: bool}}
     */
    public function map(PayrollRun $run): array
    {
        $rows = [];
        $totals = ['gross_cents' => 0, 'replacement_deduction_cents' => 0, 'other_deduction_cents' => 0, 'net_cents' => 0];
        $childrenApproved = 0;

        $items = $run->items()->with('deductions')->whereNull('voided_at')->orderBy('company_name')->orderBy('employee_name')->orderBy('id')->get();

        foreach ($items as $item) {
            /** @var PayrollItem $item */
            $children = [];

            foreach ($item->deductions->where('kind', PayrollItemDeduction::KIND_REPLACEMENT) as $deduction) {
                $children[] = $this->child($item, $deduction->detail ?? [], true, '');
            }

            foreach ($item->calc_snapshot['pending_replacements_not_deducted'] ?? [] as $pending) {
                $children[] = $this->child($item, $pending, false, 'Pending approval - calculated but NOT deducted');
            }

            usort($children, fn (array $a, array $b): int => [$a['start_date'], (string) $a['replacement_id']] <=> [$b['start_date'], (string) $b['replacement_id']]);

            $other = $item->deductions
                ->where('kind', '!=', PayrollItemDeduction::KIND_REPLACEMENT)
                ->map(fn (PayrollItemDeduction $d): array => ['type' => self::KIND_LABELS[$d->kind] ?? $d->kind, 'cents' => (int) $d->amount_cents])
                ->values()->all();

            if ($item->adjustment_cents !== 0) {
                $other[] = ['type' => 'Adjustment (prior month)', 'cents' => -$item->adjustment_cents];
            }

            $rows[] = [
                'type' => 'permanent',
                'employee_id' => $item->employee_number,
                'name' => $item->employee_name,
                'company' => $item->company_name ?? 'Missing',
                'workplace' => $item->workplace_name,
                'status' => 'Active',
                'employment_type' => 'Permanent',
                'period' => $item->period,
                'eligible_days' => $item->eligible_days,
                'days_in_month' => $item->days_in_month,
                'gross_cents' => (int) $item->gross_cents,
                'replacement_deduction_cents' => (int) $item->replacement_deduction_cents,
                'other_deductions' => $other,
                'total_deductions_cents' => (int) $item->gross_cents - (int) $item->net_cents,
                'net_cents' => (int) $item->net_cents,
                'payroll_status' => ucfirst($run->status).($item->payment_status === 'paid' ? ' / Paid' : ''),
                'note' => $item->net_cents < 0 ? 'Negative net payable - needs management decision' : '',
            ];

            foreach ($children as $child) {
                $rows[] = $child;
            }

            $totals['gross_cents'] += (int) $item->gross_cents;
            $totals['replacement_deduction_cents'] += (int) $item->replacement_deduction_cents;
            $totals['other_deduction_cents'] += (int) $item->other_deduction_cents - (int) $item->adjustment_cents;
            $totals['net_cents'] += (int) $item->net_cents;
            $childrenApproved += (int) collect($children)->where('deducted_from_parent', true)->sum('amount_cents');
        }

        return [
            'period' => $run->period,
            'rows' => $rows,
            'totals' => $totals,
            'reconciliation' => [
                'children_approved_cents' => $childrenApproved,
                'parent_deductions_cents' => $totals['replacement_deduction_cents'],
                'ok' => $childrenApproved === $totals['replacement_deduction_cents'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function child(PayrollItem $parent, array $detail, bool $deducted, string $note): array
    {
        return [
            'type' => 'temporary',
            'employee_id' => $detail['temporary_employee_number'] ?? ($detail['temporary_employee_id'] ?? 'Missing'),
            'name' => $detail['temporary_employee_name'] ?? 'Missing',
            'company' => $parent->company_name ?? 'Missing',
            'workplace' => $parent->workplace_name,
            'employment_type' => 'Temporary',
            'period' => $parent->period,
            'parent_employee_id' => $parent->employee_number,
            'replacement_id' => $detail['replacement_id'] ?? 0,
            'start_date' => $detail['start_date'] ?? '',
            'end_date' => $detail['end_date'] ?? null,
            'verified_days' => $detail['verified_days'] ?? 0,
            'daily_rate' => $detail['daily_rate'] ?? 'Missing',
            'amount_cents' => (int) ($detail['amount_cents'] ?? 0),
            'deducted_from_parent' => $deducted,
            'note' => $note,
        ];
    }
}
