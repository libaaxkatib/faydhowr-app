<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Enums\Home\HeroBannerActionType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreHeroBannerRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:200'],
            'subtitle' => ['nullable', 'string', 'max:500'],
            'image_url' => ['required', 'string', 'url', 'max:2048'],
            'action_type' => ['required', Rule::enum(HeroBannerActionType::class)],
            'action_reference' => [
                'nullable',
                'string',
                'max:2048',
                'required_unless:action_type,none',
                'prohibited_if:action_type,none',
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
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
