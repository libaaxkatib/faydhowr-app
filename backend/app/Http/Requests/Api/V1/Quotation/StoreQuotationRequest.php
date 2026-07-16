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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
            'subtotal' => ['required', 'decimal:0,2', 'min:0'],
            'discount_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'tax_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'total_amount' => ['required', 'decimal:0,2', 'min:0'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
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
