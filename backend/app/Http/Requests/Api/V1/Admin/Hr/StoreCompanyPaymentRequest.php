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
 * "Approve Paid" (`mode` full: the whole outstanding balance, no amount is sent) or "Approve Partial" (`mode` partial: an `amount` in dollars, at most two
 * decimals) for one company's section. The company is the route's section; the idempotency key is made by the screen once per attempt.
 */
class StoreCompanyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in([RecordWorkplacePaymentAction::MODE_FULL, RecordWorkplacePaymentAction::MODE_PARTIAL])],
            'amount' => ['exclude_unless:mode,partial', 'required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:500'],
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
