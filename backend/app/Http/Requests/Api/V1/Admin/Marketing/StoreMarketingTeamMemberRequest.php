<?php

namespace App\Http\Requests\Api\V1\Admin\Marketing;

use App\Enums\AdminStatus;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Add Team Member. Same rules as the existing admin creation (unique e-mail and phone among live accounts, password of at
 * least 8 characters, status), plus the team. There is deliberately NO role field: the account is always a Marketing Employee,
 * and any role sent in the request is ignored.
 */
class StoreMarketingTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => Str::lower((string) $this->input('email'))]);
        }
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:191', Rule::unique('admins', 'email')->whereNull('deleted_at')],
            'phone' => ['required', 'string', 'max:40', Rule::unique('admins', 'phone')->whereNull('deleted_at')],
            'password' => ['required', 'string', Password::min(8)],
            'team_id' => ['required', 'integer', Rule::exists('marketing_teams', 'id')],
            'status' => ['sometimes', Rule::enum(AdminStatus::class)],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()));
    }
}
