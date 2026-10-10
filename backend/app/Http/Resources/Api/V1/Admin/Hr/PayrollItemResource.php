<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_run_id' => $this->payroll_run_id,
            'period' => $this->period,
            'employee_id' => $this->employee_id,
            'employee_number' => $this->employee_number,
            'employee_name' => $this->employee_name,
            'work_assignment_id' => $this->work_assignment_id,
            'company_name' => $this->company_name,
            'workplace_name' => $this->workplace_name,
            'eligible_days' => $this->eligible_days,
            'days_in_month' => $this->days_in_month,
            'gross' => PayrollMoney::format((int) $this->gross_cents),
            'replacement_deduction' => PayrollMoney::format((int) $this->replacement_deduction_cents),
            'other_deductions' => PayrollMoney::format((int) $this->other_deduction_cents),
            'adjustment' => PayrollMoney::format((int) $this->adjustment_cents),
            'net' => PayrollMoney::format((int) $this->net_cents),
            'payment_status' => $this->payment_status,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'deductions' => $this->whenLoaded('deductions', fn () => $this->deductions->map(fn ($d) => [
                'kind' => $d->kind,
                'description' => $d->description,
                'amount' => PayrollMoney::format((int) $d->amount_cents),
                'detail' => $d->detail,
            ])->all()),
            'calc_snapshot' => $this->calc_snapshot,
        ];
    }
}
