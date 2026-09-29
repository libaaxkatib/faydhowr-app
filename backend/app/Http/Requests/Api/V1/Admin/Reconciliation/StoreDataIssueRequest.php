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

class StoreDataIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'module' => ['required', Rule::in(DataIssueModule::values())],
            'issue_type' => ['required', Rule::in(DataIssueType::values())],
            'severity' => ['required', Rule::in(DataIssueSeverity::values())],
            // A new issue may only start Open or Investigating — a closing
            // status must go through the dedicated resolve endpoint, even for
            // a brand-new issue, so resolved_by/resolved_at are never skipped.
            'status' => ['sometimes', Rule::in([DataIssueStatus::Open->value, DataIssueStatus::Investigating->value])],
            'expected_value' => ['nullable', 'string'],
            'actual_value' => ['nullable', 'string'],
            'difference_value' => ['nullable', 'string'],
            'root_cause' => ['nullable', 'string'],
            'resolution' => ['nullable', 'string'],
            'source_reference' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'affected_employees' => ['sometimes', 'array'],
            'affected_employees.*.employee_id' => ['required_with:affected_employees', 'integer', 'exists:employees,id'],
            'affected_employees.*.context_note' => ['nullable', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
