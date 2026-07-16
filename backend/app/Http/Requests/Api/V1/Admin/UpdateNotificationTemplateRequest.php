<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplateStatus;
use App\Enums\NotificationType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateNotificationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'type' => ['sometimes', Rule::enum(NotificationType::class)],
            'channel' => ['sometimes', Rule::enum(NotificationChannel::class)],
            'language' => ['sometimes', 'string', 'max:10'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title' => ['sometimes', 'string', 'max:255'],
            'message' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::enum(NotificationTemplateStatus::class)],
            'variables' => ['sometimes', 'nullable', 'array'],
            'variables.*' => ['string', 'max:100'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'Unauthenticated.',
            'UNAUTHENTICATED',
            401,
        ));
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
