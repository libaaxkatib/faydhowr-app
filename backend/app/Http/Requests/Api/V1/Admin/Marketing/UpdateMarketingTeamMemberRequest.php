<?php

namespace App\Http\Requests\Api\V1\Admin\Marketing;

use App\Enums\AdminStatus;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/** Name, phone and active/inactive only. The role, e-mail, password and team are not changed here. */
class UpdateMarketingTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $member = $this->route('admin');

        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:150'],
            'phone' => ['sometimes', 'required', 'string', 'max:40', Rule::unique('admins', 'phone')->whereNull('deleted_at')->ignore($member?->id)],
            'status' => ['sometimes', 'required', Rule::enum(AdminStatus::class)],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()));
    }
}
