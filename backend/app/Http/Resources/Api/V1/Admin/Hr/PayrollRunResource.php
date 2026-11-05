<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use App\Models\PayrollRunSection;
use App\Models\PayrollSectionReopening;
use App\Support\Hr\Payroll\PayrollApproval;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\PayrollRunChecks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class PayrollRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $totals = $this->totals ?? [];
        $money = fn (string $key): ?string => isset($totals[$key]) ? PayrollMoney::format((int) $totals[$key]) : null;

        // Company sections: the whole list when loaded (the single-month view), otherwise only the counts (the month list).
        $loaded = $this->relationLoaded('sections') ? $this->sections : null;
        $sectionsTotal = $loaded !== null ? $loaded->count() : (int) ($this->sections_count ?? 0);
        $sectionsFinalized = $loaded !== null ? $loaded->where('status', 'finalized')->count() : (int) ($this->sections_finalized_count ?? 0);
        // What each company has been paid and its derived payment / approval state (only when the lines are loaded, i.e. the single-month view).
        $states = $loaded !== null && $this->relationLoaded('items') && $loaded->isNotEmpty() ? PayrollApproval::sections($this->resource) : [];
        $byId = array_column($states, null, 'section_id');
        // Every reopening of a finalized company in this month (who, when, why, what it held), one query for the whole month.
        $reopenings = $states === [] ? collect() : PayrollSectionReopening::query()->with(['reopenedBy', 'refinalizedBy'])->where('payroll_run_id', $this->id)->orderBy('id')->get()->groupBy('payroll_run_section_id');

        return [
            'id' => $this->id,
            'period' => $this->period,
            // Companies finalize on their own; the month is "partially finalized" while some, but not all, are.
            'sectioned' => $sectionsTotal > 0,
            'sections_total' => $sectionsTotal,
            'sections_finalized' => $sectionsFinalized,
            'is_partially_finalized' => $sectionsTotal > 0 && $sectionsFinalized > 0 && $sectionsFinalized < $sectionsTotal,
            'sections' => $loaded === null ? [] : $loaded->map(fn (PayrollRunSection $s): array => $this->section($s, $byId[$s->id] ?? null, $reopenings->get($s->id)))->sortBy(fn (array $row): string => mb_strtolower((string) $row['company_name']))->values()->all(),
            // Approval is payment (owner decision 2026-11-04): the month is approved by itself when every company is fully paid. Not a status of the run: it
            // is derived from the payments, so finalizing never approves and a voided payment withdraws it. Null in the month list (no lines loaded).
            'approval' => $states === [] ? null : $this->monthApproval($states),
            'status' => $this->status,
            'currency' => $this->currency,
            'item_count' => $totals['item_count'] ?? 0,
            'totals' => [
                'gross' => $money('gross_cents'),
                'replacement_deductions' => $money('replacement_deduction_cents'),
                'other_deductions' => $money('other_deduction_cents'),
                'adjustments' => $money('adjustment_cents'),
                'net' => $money('net_cents'),
            ],
            // The warnings stored when the run was generated, plus (only when the lines are loaded, i.e. the single-run view) the read-only checks
            // that look at the lines and at last month.
            'warnings' => [...($totals['warnings'] ?? []), ...($this->relationLoaded('items') ? PayrollRunChecks::for($this->resource) : [])],
            'generated_at' => $this->generated_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'finalized_at' => $this->finalized_at?->toIso8601String(),
            'generated_by' => $this->whenLoaded('generatedBy', fn () => $this->generatedBy?->full_name),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->full_name),
            'approved_by' => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->full_name),
            'finalized_by' => $this->whenLoaded('finalizedBy', fn () => $this->finalizedBy?->full_name),
            'items' => PayrollItemResource::collection($this->whenLoaded('items')),
        ];
    }

    /**
     * The month's payment / approval counts and amounts (decimal strings next to the cents).
     *
     * @param  list<array<string, mixed>>  $states
     * @return array<string, mixed>
     */
    private function monthApproval(array $states): array
    {
        $month = PayrollApproval::month($this->resource, $states);

        return $month + [
            'total' => PayrollMoney::format($month['total_cents']),
            'paid' => PayrollMoney::format($month['paid_cents']),
            'outstanding' => PayrollMoney::format($month['outstanding_cents']),
        ];
    }

    /**
     * One company's section: its own payroll status (draft / reviewed / finalized), stamps, employee count and totals (stored with the section), what has
     * been paid / is outstanding, and its payment status (unpaid / partially_paid / approved - approved only when fully paid).
     *
     * @param  array<string, mixed>|null  $paid  the company's derived payment state (PayrollApproval::sections)
     * @return array<string, mixed>
     */
    private function section(PayrollRunSection $s, ?array $paid, ?Collection $reopenings = null): array
    {
        $t = $s->totals ?? [];
        $money = fn (string $key): string => PayrollMoney::format((int) ($t[$key] ?? 0));

        return [
            'id' => $s->id,
            'client_company_id' => $s->client_company_id,
            'company_name' => $s->clientCompany?->name,
            'category' => $s->clientCompany === null ? null : ($s->clientCompany->category?->value ?? 'company'),
            'status' => $s->status,
            'employee_count' => (int) ($t['employee_count'] ?? 0),
            'item_count' => (int) ($t['item_count'] ?? 0),
            'gross' => $money('gross_cents'),
            'net' => $money('net_cents'),
            // What there is to pay (lines with a positive net), what was paid, what is still owed; the cents are next to the decimal strings.
            'payable' => PayrollMoney::format((int) ($paid['total_cents'] ?? 0)),
            'payable_cents' => (int) ($paid['total_cents'] ?? 0),
            'paid' => PayrollMoney::format((int) ($paid['paid_cents'] ?? 0)),
            'paid_cents' => (int) ($paid['paid_cents'] ?? 0),
            'outstanding' => PayrollMoney::format((int) ($paid['outstanding_cents'] ?? 0)),
            'outstanding_cents' => (int) ($paid['outstanding_cents'] ?? 0),
            // Paid MORE than the corrected payroll says (after a finalized payroll was reopened and corrected): shown until a reconciliation settles it.
            'overpaid_cents' => (int) ($paid['overpaid_cents'] ?? 0),
            'unreconciled_overpaid_cents' => (int) ($paid['unreconciled_overpaid_cents'] ?? 0),
            'unreconciled_overpaid' => PayrollMoney::format((int) ($paid['unreconciled_overpaid_cents'] ?? 0)),
            'payment_status' => $paid['payment_status'] ?? PayrollApproval::UNPAID,
            'approved' => (bool) ($paid['approved'] ?? false),
            'can_pay' => (bool) ($paid['can_pay'] ?? false),
            // History of reopenings of this company's FINALIZED payroll: who, in which role, when, why, what it held and what it holds now.
            'reopenings' => ($reopenings ?? collect())->map(fn (PayrollSectionReopening $r): array => [
                'id' => $r->id,
                'reason' => $r->reason,
                'reopened_by' => $r->reopenedBy?->full_name,
                'reopened_by_role' => $r->reopened_by_role,
                'reopened_at' => $r->reopened_at?->toIso8601String(),
                'paid_cents_at_reopen' => $r->paid_cents_at_reopen,
                'payment_count_at_reopen' => $r->payment_count_at_reopen,
                'net_before' => PayrollMoney::format((int) ($r->totals_before['net_cents'] ?? 0)),
                'refinalized_at' => $r->refinalized_at?->toIso8601String(),
                'refinalized_by' => $r->refinalizedBy?->full_name,
                'net_after' => $r->totals_after === null ? null : PayrollMoney::format((int) ($r->totals_after['net_cents'] ?? 0)),
            ])->values()->all(),
            'reviewed_at' => $s->reviewed_at?->toIso8601String(),
            'reviewed_by' => $s->reviewedBy?->full_name,
            'approved_at' => $s->approved_at?->toIso8601String(),
            'approved_by' => $s->approvedBy?->full_name,
            'finalized_at' => $s->finalized_at?->toIso8601String(),
            'finalized_by' => $s->finalizedBy?->full_name,
            // Release 1A: this company's own generation stamp and the Temporary Replacement warnings found then (warnings only, never blocking).
            'generated_at' => $s->generated_at?->toIso8601String(),
            'generated_by' => $s->generatedBy?->full_name,
            'generation_warnings' => $s->generation_warnings ?? [],
            // Owner decision D-3: a generated company with no payable line is shown as "Generated - Nothing Payable" (a visible Draft section).
            'nothing_payable' => (int) ($t['item_count'] ?? 0) === 0,
            // Owner decision N-6 (Option 3): once ANY payment is recorded for the company, only a Super Admin may reopen its finalized payroll; the HR Manager uses a Payroll Correction.
            'has_payments' => (int) ($paid['paid_cents'] ?? 0) > 0,
        ];
    }
}
