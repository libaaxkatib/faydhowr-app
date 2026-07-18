<?php

namespace App\Http\Requests\Api\V1\Quotation;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Customers never submit pricing (Sprint 28): all pricing and payment
     * fields are explicitly prohibited on customer endpoints.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
            'requirements' => ['required', 'string', 'max:5000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'preferred_timing' => ['nullable', 'string', 'max:255'],
            'quantity_hint' => ['nullable', 'integer', 'min:1'],
            'attachment_ids' => ['nullable', 'array', 'max:10'],
            'attachment_ids.*' => ['uuid'],
            'subtotal' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'tax_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'payment_type' => ['prohibited'],
            'deposit_percentage' => ['prohibited'],
            'deposit_amount' => ['prohibited'],
            'remaining_amount' => ['prohibited'],
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
