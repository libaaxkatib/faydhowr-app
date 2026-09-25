<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateWorkforceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_category_id' => ['sometimes', 'nullable', 'integer', 'exists:employee_categories,id'],
            'position_id' => ['sometimes', 'nullable', 'integer', 'exists:positions,id'],
            'gender_requirement' => ['sometimes', 'nullable', 'string', 'in:male,female'],
            'quantity_needed' => ['sometimes', 'integer', 'min:1'],
            'requested_date' => ['sometimes', 'date'],
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
