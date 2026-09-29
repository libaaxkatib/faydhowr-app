<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeStatus;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Query-string values are always strings ("true"/"false"), which
     * Laravel's `boolean` rule rejects (it only accepts true/false/0/1/'0'/'1'
     * via strict comparison) - normalize before validating, same fix any
     * future GET-boolean-filter param on this endpoint would need.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('is_supervisor')) {
            $this->merge(['is_supervisor' => filter_var($this->query('is_supervisor'), FILTER_VALIDATE_BOOLEAN)]);
        }

        if ($this->has('office_only')) {
            $this->merge(['office_only' => filter_var($this->query('office_only'), FILTER_VALIDATE_BOOLEAN)]);
        }

        if ($this->has('profile_complete')) {
            $this->merge(['profile_complete' => filter_var($this->query('profile_complete'), FILTER_VALIDATE_BOOLEAN)]);
        }

        if ($this->has('guarantor_needed')) {
            $this->merge(['guarantor_needed' => filter_var($this->query('guarantor_needed'), FILTER_VALIDATE_BOOLEAN)]);
        }

        if ($this->has('damiin_active')) {
            $this->merge(['damiin_active' => filter_var($this->query('damiin_active'), FILTER_VALIDATE_BOOLEAN)]);
        }

        if ($this->has('historical_rejected')) {
            $this->merge(['historical_rejected' => filter_var($this->query('historical_rejected'), FILTER_VALIDATE_BOOLEAN)]);
        }
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(EmployeeStatus::values())],
            'pipeline_stage' => ['sometimes', Rule::in(EmployeePipelineStage::values())],
            'employee_category_id' => ['sometimes', 'integer', 'exists:employee_categories,id'],
            'department_id' => ['sometimes', 'integer', 'exists:departments,id'],
            'position_id' => ['sometimes', 'integer', 'exists:positions,id'],
            'location' => ['sometimes', 'string', 'max:150'],
            'is_supervisor' => ['sometimes', 'boolean'],
            'office_only' => ['sometimes', 'boolean'],
            'profile_complete' => ['sometimes', 'boolean'],
            'guarantor_needed' => ['sometimes', 'boolean'],
            'damiin_active' => ['sometimes', 'boolean'],
            'historical_rejected' => ['sometimes', 'boolean'],
            'application_date_from' => ['sometimes', 'date'],
            'application_date_to' => ['sometimes', 'date', 'after_or_equal:application_date_from'],
            'joining_date_from' => ['sometimes', 'date'],
            'joining_date_to' => ['sometimes', 'date', 'after_or_equal:joining_date_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
