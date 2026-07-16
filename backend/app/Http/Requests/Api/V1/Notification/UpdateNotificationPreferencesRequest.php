<?php

namespace App\Http\Requests\Api\V1\Notification;

use App\Enums\NotificationType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateNotificationPreferencesRequest extends FormRequest
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
            'preferences' => ['required', 'array'],
            'preferences.*.notification_type' => ['required', Rule::enum(NotificationType::class)],
            'preferences.*.in_app' => ['required', 'boolean'],
            'preferences.*.email' => ['required', 'boolean'],
            'preferences.*.sms' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $types = collect($this->input('preferences', []))
                ->pluck('notification_type')
                ->filter()
                ->map(fn (mixed $type): string => is_string($type) ? $type : (string) $type)
                ->all();

            if (count($types) !== count(array_unique($types))) {
                $validator->errors()->add(
                    'preferences',
                    'Each notification type may only appear once.',
                );
            }
        });
    }

    /**
     * @return list<array{notification_type: string, in_app: bool, email: bool, sms: bool}>
     */
    public function preferences(): array
    {
        return collect($this->validated('preferences'))
            ->map(function (array $preference): array {
                $type = $preference['notification_type'];

                return [
                    'notification_type' => $type instanceof NotificationType ? $type->value : (string) $type,
                    'in_app' => (bool) $preference['in_app'],
                    'email' => (bool) $preference['email'],
                    'sms' => (bool) $preference['sms'],
                ];
            })
            ->values()
            ->all();
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
