<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Rules\UniqueNormalisedName;
use App\Support\ApiResponse;
use App\Support\Hr\PersonName;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateDepartmentRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'string', 'max:150', new UniqueNormalisedName('departments', $this->route('department')?->id, [], 'A department with this name already exists. Names are the same even if the capital letters or spaces differ.')],
            'description' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
