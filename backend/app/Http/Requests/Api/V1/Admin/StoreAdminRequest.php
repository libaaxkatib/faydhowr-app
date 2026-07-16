<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'email' => [
                'required',
                'string',
                'email',
                'max:191',
                Rule::unique('admins', 'email')->whereNull('deleted_at'),
            ],
            'phone' => [
                'required',
                'string',
                'max:40',
                Rule::unique('admins', 'phone')->whereNull('deleted_at'),
            ],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', Rule::enum(AdminRole::class)],
            'status' => ['required', Rule::enum(AdminStatus::class)],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'Unauthenticated.',
            'UNAUTHENTICATED',
            401,
        ));
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
