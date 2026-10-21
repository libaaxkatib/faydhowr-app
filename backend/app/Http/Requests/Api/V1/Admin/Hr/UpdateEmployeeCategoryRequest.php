<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Models\EmployeeCategory;
use App\Support\ApiResponse;
use App\Support\Hr\EmployeeCategoryName;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateEmployeeCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => EmployeeCategoryName::normalise($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        /** @var EmployeeCategory|null $category */
        $category = $this->route('employeeCategory');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100', EmployeeCategoryName::mustBeUnique($category?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
