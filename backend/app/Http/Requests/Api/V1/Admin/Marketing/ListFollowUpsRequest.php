<?php

namespace App\Http\Requests\Api\V1\Admin\Marketing;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Backs the SRS §16 Today / Upcoming / Overdue views.
 */
class ListFollowUpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filter' => ['sometimes', Rule::in(['today', 'upcoming', 'overdue', 'all'])],
            'assigned_admin_id' => ['sometimes', 'integer', 'exists:admins,id'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
