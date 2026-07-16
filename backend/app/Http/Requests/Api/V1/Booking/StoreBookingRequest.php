<?php

namespace App\Http\Requests\Api\V1\Booking;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreBookingRequest extends FormRequest
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
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'service_mode_id' => ['required', 'integer', 'exists:service_modes,id'],
            'requested_date' => ['required', 'date'],
            'requested_time_window' => ['required', 'string', 'max:100'],
            'customer_address_id' => ['required', 'integer', 'exists:customer_addresses,id'],
            'customer_notes' => ['nullable', 'string', 'max:5000'],
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
