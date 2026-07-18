<?php

namespace App\Http\Requests\Api\V1\Admin\Quotations;

use App\DataTransferObjects\Quotation\QuotationRevisionData;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;

class IssueQuotationRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every revision must carry `valid_until` (Sprint 28 — final); pricing
     * consistency is re-validated inside the issuing transaction.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'subtotal' => ['required', 'decimal:0,2', 'min:0'],
            'discount_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'tax_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'total_amount' => ['required', 'decimal:0,2', 'min:0'],
            'valid_until' => ['required', 'date', 'after:now'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function toRevisionData(): QuotationRevisionData
    {
        return new QuotationRevisionData(
            subtotal: (string) $this->string('subtotal'),
            discountAmount: $this->filled('discount_amount') ? (string) $this->string('discount_amount') : '0.00',
            taxAmount: $this->filled('tax_amount') ? (string) $this->string('tax_amount') : '0.00',
            totalAmount: (string) $this->string('total_amount'),
            validUntil: Carbon::parse((string) $this->string('valid_until')),
            terms: $this->filled('terms') ? (string) $this->string('terms') : null,
            notes: $this->filled('notes') ? (string) $this->string('notes') : null,
        );
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
