<?php

namespace App\Http\Requests\Api\V1\Reviews;

use App\DataTransferObjects\Review\UpdateReviewData;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['sometimes', 'nullable', 'string', 'max:150'],
            'comment' => ['sometimes', 'nullable', 'string', 'min:10', 'max:1000'],
        ];
    }

    public function toData(): UpdateReviewData
    {
        return new UpdateReviewData(
            rating: $this->integer('rating'),
            title: $this->filled('title') ? (string) $this->string('title') : null,
            comment: $this->filled('comment') ? (string) $this->string('comment') : null,
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
