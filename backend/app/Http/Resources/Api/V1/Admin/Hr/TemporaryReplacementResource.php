<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use App\Enums\TemporaryReplacementStatus;
use App\Support\Hr\Facility\FacilityCalendar;
use App\Support\Hr\ReplacementVersion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemporaryReplacementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = FacilityCalendar::today()->toDateString();
        $endDate = $this->end_date?->toDateString();
        $expired = $this->status === TemporaryReplacementStatus::Active && $endDate !== null && $endDate < $today;
        $lifecycle = match (true) {
            $this->status === TemporaryReplacementStatus::Ended => 'ended',
            $expired => 'expired',
            $this->start_date->toDateString() > $today => 'scheduled',
            default => 'running',
        };

        $totalPaid = $this->whenLoaded('payments', fn () => number_format((float) $this->payments->sum('amount'), 2, '.', ''), '0.00');

        return [
            'id' => $this->id,
            'work_assignment_id' => $this->work_assignment_id,
            'replaced_employee_id' => $this->whenLoaded('workAssignment', fn () => $this->workAssignment?->employee_id),
            'replaced_employee_name' => $this->whenLoaded('workAssignment', fn () => $this->workAssignment?->employee?->full_name),
            'work_location_name' => $this->whenLoaded('workAssignment', fn () => $this->workAssignment?->workLocation?->name),
            'client_company_name' => $this->whenLoaded(
                'workAssignment',
                fn () => $this->workAssignment?->clientCompany?->name ?? $this->workAssignment?->workLocation?->clientCompany?->name,
            ),
            'replacement_employee_id' => $this->replacement_employee_id,
            'replacement_employee_name' => $this->whenLoaded('replacementEmployee', fn () => $this->replacementEmployee?->full_name),
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $endDate,
            // The end date the cover was planned to have when it was ended earlier (kept by N10); null when it was not ended early.
            'planned_end_date' => $this->planned_end_date?->toDateString(),
            // Worked out from the dates and today in Mogadishu, never stored: scheduled | running | expired (active, but its end date has passed) | ended.
            'lifecycle' => $lifecycle,
            'is_expired' => $expired,
            'days_overdue' => $expired ? (int) abs(CarbonImmutable::parse($endDate)->diffInDays(CarbonImmutable::parse($today))) : 0,
            'version' => ReplacementVersion::of($this->resource),
            'daily_rate' => $this->daily_rate,
            'currency' => $this->currency,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'approved' => $this->approved_at !== null,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->full_name),
            'payments' => TemporaryReplacementPaymentResource::collection($this->whenLoaded('payments')),
            'total_paid' => $totalPaid,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
