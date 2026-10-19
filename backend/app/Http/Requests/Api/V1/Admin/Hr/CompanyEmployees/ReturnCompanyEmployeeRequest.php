<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\CompanyEmployees;

use App\Enums\SalaryFrequency;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/** The original employee returns on this date; the other fields only override what is carried over from their first assignment. */
class ReturnCompanyEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'return_date' => ['required', 'date'],
            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],
            'salary_amount' => ['sometimes', 'numeric', 'gt:0', 'max:9999999999.99'],
            'salary_currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'salary_frequency' => ['sometimes', Rule::in(SalaryFrequency::values())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()));
    }
}
