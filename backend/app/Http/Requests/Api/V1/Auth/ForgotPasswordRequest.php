<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => Str::lower((string) $this->input('email')),
            ]);
        }
    }

    /**
     * Exactly one identifier: email or phone (API Design §2.4).
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required_without:phone', 'prohibits:phone', 'string', 'email', 'max:255'],
            'phone' => ['required_without:email', 'string', 'regex:'.RequestPhoneOtpRequest::PHONE_E164_PATTERN],
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
