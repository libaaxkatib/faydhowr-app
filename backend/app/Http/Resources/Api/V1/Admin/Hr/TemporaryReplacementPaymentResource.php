<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use App\Enums\AdminPermission;
use App\Support\AdminPermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemporaryReplacementPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // The calculated amount is cost information: only a payroll viewer sees it (an HR user with hr.manage alone keeps seeing what was paid).
        $admin = $request->user();
        $seesCost = $admin !== null && app(AdminPermissionResolver::class)->has($admin, AdminPermission::HrPayrollView->value, $request);

        return [
            'id' => $this->id,
            'payment_date' => $this->payment_date?->toDateString(),
            'amount' => $this->amount,
            'notes' => $this->notes,
            'override_reason' => $this->override_reason,
            ...($seesCost ? [
                'calculated_amount' => $this->calculated_amount,
                'differs_from_calculated' => $this->calculated_amount !== null && (string) $this->calculated_amount !== (string) $this->amount,
            ] : []),
            'paid_by' => $this->whenLoaded('paidBy', fn () => $this->paidBy?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
