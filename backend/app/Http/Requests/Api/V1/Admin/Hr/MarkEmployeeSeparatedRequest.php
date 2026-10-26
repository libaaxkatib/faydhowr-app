<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\EmployeeSeparationReason;
use App\Enums\SalaryFrequency;
use App\Models\AssignmentSuccession;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class MarkEmployeeSeparatedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(EmployeeSeparationReason::values())],
            'separation_date' => ['required', 'date'],
            'rehire_eligible' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            // Needed only when the replacement's start falls in an approved or finalized payroll month (a Super Admin correction).
            'correction_reason' => ['nullable', 'string', 'max:500'],
            // One replacement choice per open Permanent assignment (docs/HR_PAYROLL_RULES.md N1).
            'successors' => ['sometimes', 'nullable', 'array'],
            'successors.*.assignment_id' => ['required', 'integer', 'exists:employee_work_assignments,id'],
            'successors.*.type' => ['required', Rule::in(AssignmentSuccession::types())],
            'successors.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'successors.*.salary_amount' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            'successors.*.salary_currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'successors.*.salary_frequency' => ['sometimes', 'nullable', Rule::in(SalaryFrequency::values())],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
