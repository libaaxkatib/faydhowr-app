<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\EmployeeGender;
use App\Support\ApiResponse;
use App\Support\Hr\PersonName;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Field set matches the real employee-registration spreadsheet's structure
 * (see the HRM implementation report for the reconciliation) — age not date
 * of birth, and includes marital_status/lives_with/reference_name/
 * training_fee which the SRS's own field sketch omitted. Per HRM Phase 1
 * (docs/HRM_MARKETING_SRS.md HR §5), gender and residential location are now
 * mandatory at registration — required so Waiting-list matching (a later
 * phase) has the data it needs.
 */
class StoreEmployeeRequest extends FormRequest
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
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:40'],
            'employment_type' => ['sometimes', Rule::in(['permanent', 'temporary'])],
            'confirm_duplicate' => ['sometimes', 'boolean'],
            'duplicate_reason' => ['nullable', 'string', 'min:10', 'max:500', 'required_if_accepted:confirm_duplicate'],
            'alternate_phone' => ['nullable', 'string', 'max:40'],
            // Optional, never required, and never used for identity or duplicate matching.
            'payment_account_number' => ['nullable', 'string', 'max:50', 'regex:'.EmployeePaymentAccountRule::PATTERN],
            'location' => ['required', 'string', 'max:150'],
            'gender' => ['required', Rule::in(EmployeeGender::values())],
            'age' => ['nullable', 'integer', 'min:14', 'max:100'],
            'marital_status' => ['nullable', 'string', 'max:50'],
            'lives_with' => ['nullable', 'string', 'max:150'],
            'reference_name' => ['nullable', 'string', 'max:150'],
            'secondary_contact_name' => ['nullable', 'string', 'max:150'],
            'secondary_contact_phone' => ['nullable', 'string', 'max:40'],
            'employee_category_id' => ['required', 'integer', Rule::exists('employee_categories', 'id')->whereNull('archived_at')],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'application_date' => ['required', 'date'],
            'experience' => ['nullable', 'string'],
            'training_fee_amount' => ['nullable', 'numeric', 'min:0'],
            'training_fee_status' => ['nullable', 'string', 'max:30'],
            'source' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
