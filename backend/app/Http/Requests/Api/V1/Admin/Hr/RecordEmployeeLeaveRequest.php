<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\LeaveType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RecordEmployeeLeaveRequest extends FormRequest
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
            'leave_type' => ['required', Rule::in(LeaveType::values())],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
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
