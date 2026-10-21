<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\ClientStatus;
use App\Rules\UniqueNormalisedName;
use App\Support\ApiResponse;
use App\Support\Hr\PersonName;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * location_type is intentionally not updatable — switching a location
 * between client/office after assignments exist would make its history
 * ambiguous. Create a new location instead.
 */
class UpdateWorkLocationRequest extends FormRequest
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
        $location = $this->route('workLocation');
        $isOffice = ($location?->location_type?->value ?? $location?->location_type) === 'office';
        $scope = $isOffice ? ['location_type' => 'office'] : ['client_company_id' => $location?->client_company_id];
        $unique = new UniqueNormalisedName('work_locations', $location?->id, $scope, 'This company already has a workplace with this name. Names are the same even if the capital letters or spaces differ.');

        return [
            'name' => ['sometimes', 'filled', 'string', 'max:150', $unique],
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
