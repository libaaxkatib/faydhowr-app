<?php

namespace App\Http\Requests\Api\V1\Admin\Marketing;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AssignMarketingRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_team_id' => ['sometimes', 'nullable', 'integer', 'exists:marketing_teams,id'],
            'assigned_admin_id' => ['sometimes', 'nullable', 'integer', 'exists:admins,id'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
