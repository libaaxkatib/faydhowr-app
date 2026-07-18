<?php

namespace App\Http\Requests\Api\V1\Admin\Reviews;

use App\DataTransferObjects\Review\AdminReviewFiltersData;
use App\Enums\Review\ReviewStatus;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListAdminReviewsRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in(ReviewStatus::values())],
            'service_id' => ['sometimes', 'integer', 'min:1'],
            'rating' => ['sometimes', 'integer', 'between:1,5'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function toFilters(): AdminReviewFiltersData
    {
        return new AdminReviewFiltersData(
            status: $this->filled('status') ? ReviewStatus::from((string) $this->string('status')) : null,
            serviceId: $this->filled('service_id') ? $this->integer('service_id') : null,
            rating: $this->filled('rating') ? $this->integer('rating') : null,
            from: $this->filled('from') ? (string) $this->string('from') : null,
            to: $this->filled('to') ? (string) $this->string('to') : null,
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
