<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\SalaryFrequency;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreWorkAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_location_id' => ['required', 'integer', 'exists:work_locations,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'salary_amount' => ['required', 'numeric', 'min:0'],
            'salary_currency' => ['required', 'string', 'size:3'],
            'salary_frequency' => ['required', Rule::in(SalaryFrequency::values())],
            'notes' => ['nullable', 'string'],
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
