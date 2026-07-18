<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Enums\Home\HeroBannerActionType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Partial update (API Design §18.11); cross-field invariants against the
 * merged banner state are enforced by UpdateHeroBannerAction.
 */
class UpdateHeroBannerRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:200'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:500'],
            'image_url' => ['sometimes', 'string', 'url', 'max:2048'],
            'action_type' => ['sometimes', Rule::enum(HeroBannerActionType::class)],
            'action_reference' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
        ];
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
