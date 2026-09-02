<?php

namespace App\Http\Requests\Api\V1\Admin\Marketing;

use App\Enums\Marketing\CommissionRateType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Stores what rate is configured, not a calculation formula — see
 * App\Actions\Marketing\CreateCommissionRateAction.
 */
class StoreCommissionRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'admin_id' => ['nullable', 'integer', 'exists:admins,id'],
            'rate_type' => ['required', Rule::in(CommissionRateType::values())],
            'rate_value' => ['required', 'numeric', 'min:0'],
            'effective_from' => ['required', 'date'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
