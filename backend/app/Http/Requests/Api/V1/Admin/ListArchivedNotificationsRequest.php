<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\NotificationArchiveStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListArchivedNotificationsRequest extends FormRequest
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
            'recipient_type' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in([Admin::class, CustomerProfile::class]),
            ],
            'type' => ['sometimes', 'nullable', Rule::enum(NotificationType::class)],
            'channel' => ['sometimes', 'nullable', Rule::enum(NotificationChannel::class)],
            'status' => ['sometimes', 'nullable', Rule::enum(NotificationArchiveStatus::class)],
            'archived_from' => ['sometimes', 'nullable', 'date'],
            'archived_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:archived_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function recipientTypeFilter(): ?string
    {
        $value = $this->validated()['recipient_type'] ?? null;

        return is_string($value) ? $value : null;
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

    public function statusFilter(): ?NotificationArchiveStatus
    {
        $value = $this->validated()['status'] ?? null;

        if ($value === null) {
            return null;
        }

        return $value instanceof NotificationArchiveStatus
            ? $value
            : NotificationArchiveStatus::from($value);
    }

    public function archivedFromFilter(): ?string
    {
        $value = $this->validated()['archived_from'] ?? null;

        return is_string($value) ? $value : null;
    }

    public function archivedToFilter(): ?string
    {
        $value = $this->validated()['archived_to'] ?? null;

        return is_string($value) ? $value : null;
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
