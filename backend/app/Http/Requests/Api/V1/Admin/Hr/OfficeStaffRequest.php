<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Fields every Office Staff entry needs, whether the employee is new or already exists: emergency contact
 * and guarantor are SEPARATE sections (never the same fields), plus position and an explicitly entered or
 * confirmed monthly salary. Salary is a plain non-negative amount with at most two decimals, the same
 * "numeric, min 0" rule work assignments already use.
 */
abstract class OfficeStaffRequest extends FormRequest
{
    /** Digits with the usual punctuation, at least six characters: rejects "abc" and "1". */
    public const PHONE_PATTERN = '/^(?=(?:\D*\d){6,})[0-9+()\-\s.]{6,40}$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function sharedRules(): array
    {
        return [
            'position_id' => ['required', 'integer', 'exists:positions,id'],
            'monthly_salary' => ['required', 'numeric', 'min:0', 'max:9999999.99', 'decimal:0,2'],
            'start_date' => ['required', 'date'],
            'emergency_contact_name' => ['required', 'string', 'max:150'],
            'emergency_contact_phone' => ['required', 'string', 'regex:'.self::PHONE_PATTERN],
            'guarantor_name' => ['required', 'string', 'max:150'],
            'guarantor_phone' => ['required', 'string', 'regex:'.self::PHONE_PATTERN],
            'guarantor_relationship' => ['nullable', 'string', 'max:100'],
            'confirm_overwrite' => ['sometimes', 'array'],
            'confirm_overwrite.*' => ['string', Rule::in(['emergency_contact', 'guarantor', 'position'])],
            'confirm_additional_assignment' => ['sometimes', 'boolean'],
            'override_capacity' => ['sometimes', 'boolean'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
