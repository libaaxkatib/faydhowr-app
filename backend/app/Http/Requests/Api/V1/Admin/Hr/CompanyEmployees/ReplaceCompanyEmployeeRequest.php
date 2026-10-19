<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\CompanyEmployees;

use App\Enums\AssignmentType;
use App\Enums\SalaryFrequency;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/** Replace the employee of an open assignment. The replacement's type (Permanent or Replacement) is an explicit choice. */
class ReplaceCompanyEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('salary_frequency') === null) {
            $this->merge(['salary_frequency' => SalaryFrequency::Monthly->value]);
        }
    }

    public function rules(): array
    {
        return [
            'replacement_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'replacement_date' => ['required', 'date'],
            'original_last_day' => ['nullable', 'date', 'before:replacement_date'],
            'assignment_type' => ['required', Rule::in(AssignmentType::values())],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'salary_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'salary_currency' => ['required', 'string', 'size:3', 'alpha'],
            'salary_frequency' => ['required', Rule::in(SalaryFrequency::values())],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()));
    }
}
