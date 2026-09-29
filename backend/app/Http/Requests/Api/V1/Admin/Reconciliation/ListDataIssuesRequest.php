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

class ListDataIssuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(DataIssueStatus::values())],
            'severity' => ['sometimes', Rule::in(DataIssueSeverity::values())],
            'module' => ['sometimes', Rule::in(DataIssueModule::values())],
            'issue_type' => ['sometimes', Rule::in(DataIssueType::values())],
            'created_by' => ['sometimes', 'integer', 'exists:admins,id'],
            'resolved_by' => ['sometimes', 'integer', 'exists:admins,id'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
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
