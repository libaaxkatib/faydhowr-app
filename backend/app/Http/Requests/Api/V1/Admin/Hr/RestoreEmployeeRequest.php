<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Restoring an archived employee. Authorization is the route's permission:hr.manage middleware (the same key that guards every other change to
 * an employee). Nothing here can change the person's status: the only inputs are an optional note and the duplicate-phone confirmation.
 */
class RestoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
            'duplicate_reason' => ['nullable', 'string', 'min:10', 'max:500', 'required_if_accepted:confirm_duplicate'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
