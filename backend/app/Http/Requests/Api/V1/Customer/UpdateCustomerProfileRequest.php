<?php

namespace App\Http\Requests\Api\V1\Customer;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateCustomerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:150'],
            'avatar_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'preferred_language' => ['sometimes', 'string', Rule::in(['so', 'en', 'ar'])],
            'notification_preferences' => [
                'sometimes',
                'nullable',
                'array:push,email,booking,quotation,discussion,order,payment,marketing',
            ],
            'notification_preferences.push' => ['sometimes', 'boolean'],
            'notification_preferences.email' => ['sometimes', 'boolean'],
            'notification_preferences.booking' => ['sometimes', 'boolean'],
            'notification_preferences.quotation' => ['sometimes', 'boolean'],
            'notification_preferences.discussion' => ['sometimes', 'boolean'],
            'notification_preferences.order' => ['sometimes', 'boolean'],
            'notification_preferences.payment' => ['sometimes', 'boolean'],
            'notification_preferences.marketing' => ['sometimes', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'The given data was invalid.',
            'VALIDATION_ERROR',
            422,
            $validator->errors(),
        ));
    }
}
