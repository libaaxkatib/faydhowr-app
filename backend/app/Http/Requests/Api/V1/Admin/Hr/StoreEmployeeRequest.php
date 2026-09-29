<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\EmployeeGender;
use App\Support\ApiResponse;
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

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:40'],
            'alternate_phone' => ['nullable', 'string', 'max:40'],
            'location' => ['required', 'string', 'max:150'],
            'gender' => ['required', Rule::in(EmployeeGender::values())],
            'age' => ['nullable', 'integer', 'min:14', 'max:100'],
            'marital_status' => ['nullable', 'string', 'max:50'],
            'lives_with' => ['nullable', 'string', 'max:150'],
            'reference_name' => ['nullable', 'string', 'max:150'],
            'secondary_contact_name' => ['nullable', 'string', 'max:150'],
            'secondary_contact_phone' => ['nullable', 'string', 'max:40'],
            'employee_category_id' => ['required', 'integer', 'exists:employee_categories,id'],
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
