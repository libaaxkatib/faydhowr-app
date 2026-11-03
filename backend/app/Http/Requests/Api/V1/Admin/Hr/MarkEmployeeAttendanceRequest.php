<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\AttendanceStatus;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class MarkEmployeeAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The company this belongs to (each company owns its own payroll). Omitted: taken from where the employee works when that is one company; required when several. null = the office.
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'date' => ['required', 'date'],
            'status' => ['required', Rule::in(AttendanceStatus::values())],
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
