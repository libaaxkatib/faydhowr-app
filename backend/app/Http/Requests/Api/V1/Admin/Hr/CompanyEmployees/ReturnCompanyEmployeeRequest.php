<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\CompanyEmployees;

use App\Enums\AssignmentShift;
use App\Enums\SalaryFrequency;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * The original employee returns on this date as a NEW Permanent assignment. The salary is never assumed unchanged: HR must send the
 * salary they confirm or edit (the screen proposes the previous one).
 */
class ReturnCompanyEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'return_date' => ['required', 'date'],
            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],
            'salary_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'salary_currency' => ['required', 'string', 'size:3', 'alpha'],
            'salary_frequency' => ['required', Rule::in(SalaryFrequency::values())],
            'correction_reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'shift' => ['sometimes', 'nullable', Rule::in(AssignmentShift::values())],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()));
    }
}
