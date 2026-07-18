<?php

namespace App\Http\Requests\Api\V1\Payment;

use App\DataTransferObjects\Payment\InitializePaymentData;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStage;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class InitializePaymentRequest extends FormRequest
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
            'payable_type' => ['required', 'string', Rule::in(['order', 'store_order'])],
            'payable_id' => ['required', 'integer'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'payment_stage' => ['required', Rule::enum(PaymentStage::class)],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }

    public function toData(): InitializePaymentData
    {
        return new InitializePaymentData(
            payableType: (string) $this->validated('payable_type'),
            payableId: (int) $this->validated('payable_id'),
            paymentMethod: PaymentMethod::from((string) $this->validated('payment_method')),
            paymentStage: PaymentStage::from((string) $this->validated('payment_stage')),
            idempotencyKey: (string) $this->validated('idempotency_key'),
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
