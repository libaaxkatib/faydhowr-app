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

/** Add an EXISTING HR employee to the company. The employee is named by id; the type is an explicit choice. */
class AssignCompanyEmployeeRequest extends FormRequest
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
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'assignment_type' => ['required', Rule::in(AssignmentType::values())],
            'position_id' => ['required', 'integer', 'exists:positions,id'],
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
            'start_date' => ['required', 'date'],
            'salary_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'salary_currency' => ['required', 'string', 'size:3', 'alpha'],
            'salary_frequency' => ['required', Rule::in(SalaryFrequency::values())],
            'notes' => ['nullable', 'string', 'max:1000'],
            'shift' => ['sometimes', 'nullable', Rule::in(AssignmentShift::values())],
            // Sent as true after the user confirms "assign them to this company as well" (only asked while the multi-company policy is ON).
            'confirm_additional_company' => ['sometimes', 'boolean'],
            'correction_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()));
    }
}
