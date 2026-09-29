<?php

namespace App\Http\Requests\Api\V1\Admin\Reconciliation;

use App\Enums\DataIssueStatus;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ResolveDataIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                DataIssueStatus::Resolved->value,
                DataIssueStatus::AcceptedDifference->value,
                DataIssueStatus::CannotResolve->value,
            ])],
            'resolution' => ['required', 'string'],
            'root_cause' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
