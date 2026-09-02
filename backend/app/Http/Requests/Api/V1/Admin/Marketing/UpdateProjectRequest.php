<?php

namespace App\Http\Requests\Api\V1\Admin\Marketing;

use App\Enums\Marketing\ResponsiblePartyType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'responsible_party_type' => ['sometimes', Rule::in(ResponsiblePartyType::values())],
            'company_name' => ['required_if:responsible_party_type,company', 'nullable', 'string', 'max:200'],
            'responsible_person_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'location' => ['sometimes', 'nullable', 'string', 'max:150'],
            'project_type' => ['sometimes', 'nullable', 'string', 'max:150'],
            'project_size' => ['sometimes', 'nullable', 'string', 'max:100'],
            'construction_completion_date' => ['sometimes', 'nullable', 'date'],
            'fayadhowr_work_date' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string'],
            'feedback' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
