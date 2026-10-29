<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\ClientCategory;
use App\Enums\ClientStatus;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Optional filters of the Companies & Workplaces list. A call with none of them (the dropdowns on other screens) still returns every company; a
 * call with `page` or `per_page` returns one page plus the counts the screen shows.
 */
class ListClientCompaniesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', Rule::enum(ClientCategory::class)],
            'status' => ['sometimes', 'nullable', Rule::enum(ClientStatus::class)],
            'workplaces' => ['sometimes', 'nullable', Rule::in(['with', 'without'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()));
    }
}
