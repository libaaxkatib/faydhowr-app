<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Support\ApiResponse;
use App\Support\Hr\Payroll\DeductionRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RecordEmployeePenaltyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        DeductionRules::normalize($this);
    }

    public function rules(): array
    {
        return [
            // The company this belongs to (each company owns its own payroll). Omitted: taken from where the employee works when that is one company; required when several. null = the office.
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'penalty_date' => DeductionRules::date(),
            'reason' => DeductionRules::reason(),
            'deduction_amount' => DeductionRules::amount(),
            'currency' => DeductionRules::currency(),
            'payroll_period' => DeductionRules::period(),
            'notes' => ['nullable', 'string', 'max:2000'],
            // Release 1B: the same key sent twice records ONE penalty; confirm_duplicate says "yes, this really is a second identical one".
            'idempotency_key' => DeductionRules::idempotencyKey(),
            'confirm_duplicate' => ['nullable', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
