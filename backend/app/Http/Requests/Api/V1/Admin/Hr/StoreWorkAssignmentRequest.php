<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\AssignmentType;
use App\Enums\EmployeeStatus;
use App\Enums\SalaryFrequency;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Work assignments may only be created for ACTIVE employees — the recruitment
 * pipeline (applicant/recruitment/practical/waiting/approved) must complete
 * first. Checked here against the route-bound Employee, not in the Action,
 * so it surfaces as a normal validation error alongside the other rules.
 */
class StoreWorkAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
            'client_company_id' => ['nullable', 'required_without:work_location_id', 'integer', 'exists:client_companies,id'],
            'replaces_assignment_id' => ['nullable', 'integer', 'exists:employee_work_assignments,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'assignment_type' => ['sometimes', Rule::in(AssignmentType::values())],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // A Replacement has no salary of its own (it inherits the replaced Permanent's).
            'salary_amount' => ['required_unless:assignment_type,replacement', 'nullable', 'numeric', 'min:0'],
            'salary_currency' => ['required_unless:assignment_type,replacement', 'nullable', 'string', 'size:3'],
            'salary_frequency' => ['required_unless:assignment_type,replacement', 'nullable', Rule::in(SalaryFrequency::values())],
            'acknowledge_temporary_as_replacement' => ['sometimes', 'boolean'],
            'correction_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
            'override_capacity' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employee = $this->route('employee');

            if ($employee instanceof Employee && $employee->status !== EmployeeStatus::Active) {
                $validator->errors()->add(
                    'employee_status',
                    'Work assignments are available only for Active employees.',
                );
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
