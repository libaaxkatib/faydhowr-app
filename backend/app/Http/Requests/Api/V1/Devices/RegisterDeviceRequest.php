<?php

namespace App\Http\Requests\Api\V1\Devices;

use App\DataTransferObjects\Device\RegisterDeviceData;
use App\Enums\DevicePlatform;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:100'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'push_token' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function toData(): RegisterDeviceData
    {
        return new RegisterDeviceData(
            deviceId: (string) $this->validated('device_id'),
            platform: DevicePlatform::from((string) $this->validated('platform')),
            pushToken: $this->validated('push_token'),
            appVersion: $this->validated('app_version'),
        );
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
