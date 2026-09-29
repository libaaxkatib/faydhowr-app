<?php

namespace App\Http\Requests\Api\V1\Admin\Reconciliation;

use App\Enums\DataIssueModule;
use App\Enums\DataIssueSeverity;
use App\Enums\DataIssueStatus;
use App\Enums\DataIssueType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateDataIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'module' => ['sometimes', Rule::in(DataIssueModule::values())],
            'issue_type' => ['sometimes', Rule::in(DataIssueType::values())],
            'severity' => ['sometimes', Rule::in(DataIssueSeverity::values())],
            // Only non-closing moves here — Resolved/Accepted Difference/Cannot
            // Resolve must go through the dedicated resolve endpoint.
            'status' => ['sometimes', Rule::in([DataIssueStatus::Open->value, DataIssueStatus::Investigating->value])],
            'expected_value' => ['sometimes', 'nullable', 'string'],
            'actual_value' => ['sometimes', 'nullable', 'string'],
            'difference_value' => ['sometimes', 'nullable', 'string'],
            'root_cause' => ['sometimes', 'nullable', 'string'],
            'resolution' => ['sometimes', 'nullable', 'string'],
            'source_reference' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'add_affected_employees' => ['sometimes', 'array'],
            'add_affected_employees.*.employee_id' => ['required_with:add_affected_employees', 'integer', 'exists:employees,id'],
            'add_affected_employees.*.context_note' => ['nullable', 'string'],
            'remove_affected_record_ids' => ['sometimes', 'array'],
            'remove_affected_record_ids.*' => ['integer', 'exists:data_issue_affected_records,id'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
