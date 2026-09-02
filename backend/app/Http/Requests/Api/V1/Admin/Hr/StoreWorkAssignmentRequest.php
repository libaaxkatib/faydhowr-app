<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

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
            'work_location_id' => ['required', 'integer', 'exists:work_locations,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'salary_amount' => ['required', 'numeric', 'min:0'],
            'salary_currency' => ['required', 'string', 'size:3'],
            'salary_frequency' => ['required', Rule::in(SalaryFrequency::values())],
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
