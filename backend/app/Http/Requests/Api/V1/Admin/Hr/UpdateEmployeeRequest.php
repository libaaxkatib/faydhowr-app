<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\EmployeeGender;
use App\Models\EmployeeCategory;
use App\Support\ApiResponse;
use App\Support\Hr\PersonName;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('full_name') && is_string($this->input('full_name'))) {
            $this->merge(['full_name' => PersonName::normalise($this->input('full_name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'filled', 'string', 'max:150'],
            // Nullable: 242 real production employees legitimately have no phone on
            // record (Excel HR migration) — the HR Manager must be able to save an
            // otherwise-unrelated edit without being forced to fabricate one.
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'alternate_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            // Optional and separate from the registration phone. Omitting it keeps the stored value; null clears it.
            'payment_account_number' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:'.EmployeePaymentAccountRule::PATTERN],
            // HR confirms that a flagged difference between the imported payment number and the registration phone is intended.
            'accept_payment_account_mismatch' => ['sometimes', 'boolean'],
            'employment_type' => ['sometimes', Rule::in(['permanent', 'temporary'])],
            'confirm_duplicate' => ['sometimes', 'boolean'],
            'duplicate_reason' => ['nullable', 'string', 'min:10', 'max:500', 'required_if_accepted:confirm_duplicate'],
            'location' => ['sometimes', 'nullable', 'string', 'max:150'],
            'gender' => ['sometimes', Rule::in(EmployeeGender::values())],
            'age' => ['sometimes', 'nullable', 'integer', 'min:14', 'max:100'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'lives_with' => ['sometimes', 'nullable', 'string', 'max:150'],
            'reference_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'secondary_contact_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'secondary_contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'employee_category_id' => ['sometimes', 'integer', 'exists:employee_categories,id', function (string $attribute, mixed $value, callable $fail): void {
                $current = $this->route('employee')?->employee_category_id;
                if ((int) $value !== (int) $current && EmployeeCategory::query()->whereKey($value)->whereNotNull('archived_at')->exists()) {
                    $fail('This category is archived and cannot be chosen. Restore it first.');
                }
            }],
            'category_specialization' => ['sometimes', 'nullable', 'string', 'max:60'],
            'department_id' => ['sometimes', 'nullable', 'integer', 'exists:departments,id'],
            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],
            // Nullable: 234 real production employees legitimately have no application
            // date on record (Excel HR migration) — never fabricated.
            'application_date' => ['sometimes', 'nullable', 'date'],
            'joining_date' => ['sometimes', 'nullable', 'date'],
            'experience' => ['sometimes', 'nullable', 'string'],
            'training_fee_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'training_fee_status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'source' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
