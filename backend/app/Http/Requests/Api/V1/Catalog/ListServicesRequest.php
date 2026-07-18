<?php

namespace App\Http\Requests\Api\V1\Catalog;

use App\DataTransferObjects\Catalog\ServiceCatalogFiltersData;
use App\Enums\ServiceMode;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'integer'],
            'mode' => ['sometimes', 'string', Rule::enum(ServiceMode::class)],
            'city' => ['sometimes', 'string', Rule::in(['Mogadishu', 'Hargeisa'])],
            'sort' => ['sometimes', 'string', Rule::in(['display_order', 'name'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function toFilters(): ServiceCatalogFiltersData
    {
        return new ServiceCatalogFiltersData(
            categoryId: $this->filled('category_id') ? $this->integer('category_id') : null,
            mode: $this->filled('mode') ? ServiceMode::from($this->string('mode')->toString()) : null,
            city: $this->filled('city') ? $this->string('city')->toString() : null,
            sort: $this->filled('sort') ? $this->string('sort')->toString() : 'display_order',
            perPage: $this->filled('per_page') ? $this->integer('per_page') : 20,
        );
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'The given data was invalid.',
            'VALIDATION_ERROR',
            422,
            $validator->errors(),
        ));
    }
}
