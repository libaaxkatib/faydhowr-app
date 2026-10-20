<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\CompanyEmployees;

use App\Enums\AssignmentShift;
use App\Enums\AssignmentType;
use App\Enums\SalaryFrequency;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/** Company-specific assignment details only - never the employee's HR record. Status is not editable here (use Stop/Replace/Return). */
class EditCompanyAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],
            'assignment_type' => ['sometimes', Rule::in(AssignmentType::values())],
            'start_date' => ['sometimes', 'date'],
            'salary_amount' => ['sometimes', 'numeric', 'gt:0', 'max:9999999999.99'],
            'salary_currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'salary_frequency' => ['sometimes', Rule::in(SalaryFrequency::values())],
            'salary_effective_from' => ['sometimes', 'date'],
            'salary_change_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'shift' => ['sometimes', 'nullable', Rule::in(AssignmentShift::values())],
            'correction_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()));
    }
}
