<?php

namespace App\Http\Requests\Api\V1\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListNotificationsRequest extends FormRequest
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
            'status' => ['sometimes', 'nullable', Rule::enum(NotificationStatus::class)],
            'type' => ['sometimes', 'nullable', Rule::enum(NotificationType::class)],
            'channel' => ['sometimes', 'nullable', Rule::enum(NotificationChannel::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function statusFilter(): ?NotificationStatus
    {
        $value = $this->validated()['status'] ?? null;

        if ($value === null) {
            return null;
        }

        return $value instanceof NotificationStatus
            ? $value
            : NotificationStatus::from($value);
    }

    public function typeFilter(): ?NotificationType
    {
        $value = $this->validated()['type'] ?? null;

        if ($value === null) {
            return null;
        }

        return $value instanceof NotificationType
            ? $value
            : NotificationType::from($value);
    }

    public function channelFilter(): ?NotificationChannel
    {
        $value = $this->validated()['channel'] ?? null;

        if ($value === null) {
            return null;
        }

        return $value instanceof NotificationChannel
            ? $value
            : NotificationChannel::from($value);
    }

    public function perPage(): int
    {
        return min(max((int) ($this->validated()['per_page'] ?? 15), 1), 100);
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
