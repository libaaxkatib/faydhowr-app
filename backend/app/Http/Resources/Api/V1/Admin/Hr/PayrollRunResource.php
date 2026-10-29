<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

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

        return [
            'id' => $this->id,
            'period' => $this->period,
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
}
