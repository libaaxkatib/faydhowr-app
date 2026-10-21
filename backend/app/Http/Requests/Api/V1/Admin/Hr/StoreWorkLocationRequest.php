<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\ClientStatus;
use App\Enums\LocationType;
use App\Rules\UniqueNormalisedName;
use App\Support\ApiResponse;
use App\Support\Hr\PersonName;
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

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => PersonName::normalise($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        // A workplace name is unique inside its company (the Fayadhowr Office among the offices), ignoring capital letters and spaces.
        $scope = $this->input('location_type') === 'office' ? ['location_type' => 'office'] : ['client_company_id' => $this->input('client_company_id')];
        $unique = new UniqueNormalisedName('work_locations', null, $scope, 'This company already has a workplace with this name. Names are the same even if the capital letters or spaces differ.');

        return [
            'location_type' => ['required', Rule::in(LocationType::values())],
            'client_company_id' => [
                'required_if:location_type,client',
                'prohibited_if:location_type,office',
                'nullable',
                'integer',
                'exists:client_companies,id',
            ],
            'name' => ['required', 'string', 'max:150', $unique],
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
