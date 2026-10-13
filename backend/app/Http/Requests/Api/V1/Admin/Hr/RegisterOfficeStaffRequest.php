<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\EmployeeGender;
use Illuminate\Validation\Rule;

/** A brand-new employee registered straight into Office Staff (no recruitment pipeline). */
class RegisterOfficeStaffRequest extends OfficeStaffRequest
{
    public function rules(): array
    {
        return [
            ...$this->sharedRules(),
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'regex:'.self::PHONE_PATTERN],
            'alternate_phone' => ['nullable', 'string', 'max:40'],
            // No category field: a new Office Staff member always gets the "Office Staff" category, set by the server.
            'gender' => ['nullable', Rule::in(EmployeeGender::values())],
            'location' => ['nullable', 'string', 'max:150'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
            'duplicate_reason' => ['nullable', 'string', 'min:10', 'max:500', 'required_if_accepted:confirm_duplicate'],
        ];
    }
}
