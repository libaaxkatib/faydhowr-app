<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The only fields a correction can carry. The covered assignment (and so the covered Permanent employee), the status, the approval and the payments are not
 * among them: sending the covered assignment is refused outright instead of being ignored.
 */
class UpdateTemporaryReplacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'replacement_employee_id' => ['sometimes', 'required', 'integer', 'exists:employees,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'change_reason' => ['required', 'string', 'min:10', 'max:1000'],
            'version' => ['required', 'string', 'max:64'],
            'work_assignment_id' => ['prohibited'],
            'status' => ['prohibited'],
            'approved_at' => ['prohibited'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
