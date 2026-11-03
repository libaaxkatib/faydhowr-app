<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use App\Models\PayrollRunSection;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\PayrollRunChecks;
use App\Support\Hr\Payroll\WorkplacePayroll;
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
        $paid = $loaded !== null && $this->relationLoaded('items') && $loaded->isNotEmpty() ? WorkplacePayroll::companyTotals($this->resource) : [];

        return [
            'id' => $this->id,
            'period' => $this->period,
            // Companies finalize on their own; the month is "partially finalized" while some, but not all, are.
            'sectioned' => $sectionsTotal > 0,
            'sections_total' => $sectionsTotal,
            'sections_finalized' => $sectionsFinalized,
            'is_partially_finalized' => $sectionsTotal > 0 && $sectionsFinalized > 0 && $sectionsFinalized < $sectionsTotal,
            'sections' => $loaded === null ? [] : $loaded->map(fn (PayrollRunSection $s): array => $this->section($s, $paid[$s->client_company_id ?? 'none'] ?? null))->sortBy(fn (array $row): string => mb_strtolower((string) $row['company_name']))->values()->all(),
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
     * One company's section: its own status, stamps, employee count and totals (stored with the section), and what has been paid / is outstanding.
     *
     * @param  array{paid_cents: int, outstanding_cents: int}|null  $paid
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
            'paid' => PayrollMoney::format((int) ($paid['paid_cents'] ?? 0)),
            'outstanding' => PayrollMoney::format((int) ($paid['outstanding_cents'] ?? 0)),
            'reviewed_at' => $s->reviewed_at?->toIso8601String(),
            'reviewed_by' => $s->reviewedBy?->full_name,
            'approved_at' => $s->approved_at?->toIso8601String(),
            'approved_by' => $s->approvedBy?->full_name,
            'finalized_at' => $s->finalized_at?->toIso8601String(),
            'finalized_by' => $s->finalizedBy?->full_name,
        ];
    }
}
