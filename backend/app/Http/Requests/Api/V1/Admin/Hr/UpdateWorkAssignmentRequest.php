<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\AssignmentShift;
use App\Enums\AssignmentType;
use App\Enums\SalaryFrequency;
use App\Support\ApiResponse;
use App\Support\Hr\SuccessorRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateWorkAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_location_id' => ['sometimes', 'integer', 'exists:work_locations,id'],
            'override_capacity' => ['sometimes', 'boolean'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'assignment_type' => ['sometimes', Rule::in(AssignmentType::values())],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'salary_amount' => ['sometimes', 'numeric', 'min:0'],
            'salary_currency' => ['sometimes', 'string', 'size:3'],
            'salary_frequency' => ['sometimes', Rule::in(SalaryFrequency::values())],
            'salary_effective_from' => ['sometimes', 'date'],
            'salary_change_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
            'shift' => ['sometimes', 'nullable', Rule::in(AssignmentShift::values())],
            'correction_reason' => ['nullable', 'string', 'max:500'],
            ...SuccessorRules::for('successor'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
