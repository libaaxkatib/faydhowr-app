<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\ClientStatus;
use App\Enums\LocationType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * client_company_id is required only for location_type=client and must be
 * absent for location_type=office (the Fayadhowr Office is never tied to a
 * client company) — same conditional-required convention already used for
 * Marketing Project's company_name.
 */
class StoreWorkLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_type' => ['required', Rule::in(LocationType::values())],
            'client_company_id' => [
                'required_if:location_type,client',
                'prohibited_if:location_type,office',
                'nullable',
                'integer',
                'exists:client_companies,id',
            ],
            'name' => ['required', 'string', 'max:150'],
            'location' => ['nullable', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(ClientStatus::values())],
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
