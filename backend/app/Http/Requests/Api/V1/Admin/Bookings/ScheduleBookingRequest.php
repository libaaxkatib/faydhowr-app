<?php

namespace App\Http\Requests\Api\V1\Admin\Bookings;

use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ScheduleBookingRequest extends FormRequest
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
            'scheduled_start_at' => ['required', 'date', 'after:now'],
            'scheduled_end_at' => ['required', 'date', 'after:scheduled_start_at'],
        ];
    }

    public function scheduledStartAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->string('scheduled_start_at'));
    }

    public function scheduledEndAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->string('scheduled_end_at'));
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
