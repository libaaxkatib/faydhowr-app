<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Actions\Hr\RecordWorkplacePaymentAction;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * A payment for one workplace: `mode` full pays the whole outstanding balance (no amount is sent), partial needs an `amount` in dollars with at most
 * two decimals. The workplace is named by ids only. The idempotency key is made by the screen once per attempt.
 */
class StoreWorkplacePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
            'mode' => ['required', Rule::in([RecordWorkplacePaymentAction::MODE_FULL, RecordWorkplacePaymentAction::MODE_PARTIAL])],
            'amount' => ['exclude_unless:mode,partial', 'required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('client_company_id') === null && $this->input('work_location_id') === null) {
                $validator->errors()->add('client_company_id', 'Choose the workplace to pay.');
            }
        }];
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
