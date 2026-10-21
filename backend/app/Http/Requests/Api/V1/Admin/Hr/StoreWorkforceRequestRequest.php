<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreWorkforceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
            'client_company_id' => ['nullable', 'required_without:work_location_id', 'integer', 'exists:client_companies,id'],
            'employee_category_id' => ['nullable', 'integer', Rule::exists('employee_categories', 'id')->whereNull('archived_at')],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'gender_requirement' => ['nullable', 'string', 'in:male,female'],
            'quantity_needed' => ['nullable', 'integer', 'min:1'],
            'requested_date' => ['required', 'date'],
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
