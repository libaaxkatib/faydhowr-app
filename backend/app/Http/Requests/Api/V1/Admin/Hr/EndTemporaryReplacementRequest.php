<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class EndTemporaryReplacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'end_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            // The caller confirms that a last day later than the recorded end lengthens the cover (the screen shows the old and new dates and the cost first).
            'confirm_extension' => ['nullable', 'boolean'],
            // Closed payroll only: a Super Admin's written reason for the correction.
            'reason' => ['nullable', 'string', 'max:1000'],
            'version' => ['nullable', 'string', 'max:64'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
