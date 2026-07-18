<?php

namespace App\Http\Requests\Api\V1\Quotation;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateQuotationRequest extends FormRequest
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
            'booking_id' => ['sometimes', 'nullable', 'integer', 'exists:bookings,id'],
            'requirements' => ['sometimes', 'string', 'max:5000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'preferred_timing' => ['sometimes', 'nullable', 'string', 'max:255'],
            'quantity_hint' => ['sometimes', 'nullable', 'integer', 'min:1'],
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
