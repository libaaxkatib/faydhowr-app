<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Models\Admin;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateAdminRequest extends FormRequest
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
        /** @var Admin $admin */
        $admin = $this->route('admin');

        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:191',
                Rule::unique('admins', 'email')
                    ->whereNull('deleted_at')
                    ->ignore($admin->id),
            ],
            'phone' => [
                'sometimes',
                'required',
                'string',
                'max:40',
                Rule::unique('admins', 'phone')
                    ->whereNull('deleted_at')
                    ->ignore($admin->id),
            ],
            'role' => ['sometimes', 'required', Rule::enum(AdminRole::class)],
            'status' => ['sometimes', 'required', Rule::enum(AdminStatus::class)],
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
