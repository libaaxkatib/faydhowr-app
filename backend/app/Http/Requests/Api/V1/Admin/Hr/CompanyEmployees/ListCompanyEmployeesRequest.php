<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\CompanyEmployees;

use App\Enums\AssignmentType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/** Month (YYYY-MM, default: this month) and optional filters. */
class ListCompanyEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'ended'])],
            'position_id' => ['sometimes', 'nullable', 'integer'],
            'assignment_type' => ['sometimes', 'nullable', Rule::in(AssignmentType::values())],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()));
    }
}
