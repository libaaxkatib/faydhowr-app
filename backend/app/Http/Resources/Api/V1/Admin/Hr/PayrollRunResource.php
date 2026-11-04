<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use App\Models\PayrollRunSection;
use App\Support\Hr\Payroll\PayrollApproval;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\PayrollRunChecks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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

        return [
            'id' => $this->id,
            'period' => $this->period,
            // Companies finalize on their own; the month is "partially finalized" while some, but not all, are.
            'sectioned' => $sectionsTotal > 0,
            'sections_total' => $sectionsTotal,
            'sections_finalized' => $sectionsFinalized,
            'is_partially_finalized' => $sectionsTotal > 0 && $sectionsFinalized > 0 && $sectionsFinalized < $sectionsTotal,
            'sections' => $loaded === null ? [] : $loaded->map(fn (PayrollRunSection $s): array => $this->section($s, $byId[$s->id] ?? null))->sortBy(fn (array $row): string => mb_strtolower((string) $row['company_name']))->values()->all(),
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
    private function section(PayrollRunSection $s, ?array $paid): array
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
            'payment_status' => $paid['payment_status'] ?? PayrollApproval::UNPAID,
            'approved' => (bool) ($paid['approved'] ?? false),
            'can_pay' => (bool) ($paid['can_pay'] ?? false),
            'reviewed_at' => $s->reviewed_at?->toIso8601String(),
            'reviewed_by' => $s->reviewedBy?->full_name,
            'approved_at' => $s->approved_at?->toIso8601String(),
            'approved_by' => $s->approvedBy?->full_name,
            'finalized_at' => $s->finalized_at?->toIso8601String(),
            'finalized_by' => $s->finalizedBy?->full_name,
        ];
    }
}
