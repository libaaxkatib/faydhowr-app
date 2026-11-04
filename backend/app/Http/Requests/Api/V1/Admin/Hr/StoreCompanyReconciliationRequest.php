<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Actions\Hr\RecordCompanyReconciliationAction;
use App\Models\PayrollWorkplaceReconciliation;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/** Settle the excess of an overpaid company: `mode` full (all of it) or partial (an `amount` in dollars), how it was settled and why. */
class StoreCompanyReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in([RecordCompanyReconciliationAction::MODE_FULL, RecordCompanyReconciliationAction::MODE_PARTIAL])],
            'amount' => ['exclude_unless:mode,partial', 'required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'method' => ['required', Rule::in(PayrollWorkplaceReconciliation::METHODS)],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'settled_on' => ['required', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ];
    }

    public function amountCents(): ?int
    {
        $amount = $this->validated('amount');

        return $amount === null ? null : PayrollMoney::toCents($amount);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
