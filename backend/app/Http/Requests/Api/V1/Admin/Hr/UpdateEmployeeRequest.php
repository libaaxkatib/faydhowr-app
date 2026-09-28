<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\EmployeeGender;
use App\Support\ApiResponse;
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

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'max:150'],
            // Nullable: 242 real production employees legitimately have no phone on
            // record (Excel HR migration) — the HR Manager must be able to save an
            // otherwise-unrelated edit without being forced to fabricate one.
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'alternate_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'location' => ['sometimes', 'nullable', 'string', 'max:150'],
            'gender' => ['sometimes', Rule::in(EmployeeGender::values())],
            'age' => ['sometimes', 'nullable', 'integer', 'min:14', 'max:100'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'lives_with' => ['sometimes', 'nullable', 'string', 'max:150'],
            'reference_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'employee_category_id' => ['sometimes', 'integer', 'exists:employee_categories,id'],
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
