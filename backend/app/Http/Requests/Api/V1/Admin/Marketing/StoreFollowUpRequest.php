<?php

namespace App\Http\Requests\Api\V1\Admin\Marketing;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * follow_up_date is required and validated as an actual date — SRS §14's
 * explicit rule against storing "1 month later" as free text.
 */
class StoreFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'follow_up_date' => ['required', 'date'],
            'assigned_admin_id' => ['nullable', 'integer', 'exists:admins,id'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
