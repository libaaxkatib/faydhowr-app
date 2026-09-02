<?php

namespace App\Http\Requests\Api\V1\Admin\Marketing;

use App\Enums\Marketing\MarketingRecordStatus;
use App\Enums\Marketing\MarketingRecordType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListMarketingRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:150'],
            'type' => ['sometimes', Rule::in(['xarun', 'project'])],
            'status' => ['sometimes', Rule::in(MarketingRecordStatus::values())],
            'assigned_team_id' => ['sometimes', 'integer', 'exists:marketing_teams,id'],
            'assigned_admin_id' => ['sometimes', 'integer', 'exists:admins,id'],
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
