<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\CompanyEmployees;

use App\Support\ApiResponse;
use App\Support\Hr\SuccessorRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Last day and a reason, both required. Ends this assignment only. */
class StopCompanyAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'end_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'correction_reason' => ['nullable', 'string', 'max:500'],
            // Required by the action for an open Permanent position (docs/HR_PAYROLL_RULES.md N1): a Temporary who covered it, or a Waiting List person.
            ...SuccessorRules::for('successor'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()));
    }
}
