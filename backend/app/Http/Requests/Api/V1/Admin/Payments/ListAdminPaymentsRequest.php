<?php

namespace App\Http\Requests\Api\V1\Admin\Payments;

use App\DataTransferObjects\Payment\AdminPaymentFiltersData;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStage;
use App\Enums\PaymentStatus;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListAdminPaymentsRequest extends FormRequest
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
            'status' => ['sometimes', Rule::enum(PaymentStatus::class)],
            'payment_method' => ['sometimes', Rule::enum(PaymentMethod::class)],
            'payment_stage' => ['sometimes', Rule::enum(PaymentStage::class)],
            'payable_type' => ['sometimes', 'string', Rule::in(['order', 'store_order'])],
            'customer_profile_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function toFilters(): AdminPaymentFiltersData
    {
        return new AdminPaymentFiltersData(
            status: $this->filled('status') ? PaymentStatus::from((string) $this->string('status')) : null,
            paymentMethod: $this->filled('payment_method') ? PaymentMethod::from((string) $this->string('payment_method')) : null,
            paymentStage: $this->filled('payment_stage') ? PaymentStage::from((string) $this->string('payment_stage')) : null,
            payableType: $this->filled('payable_type') ? (string) $this->string('payable_type') : null,
            customerProfileId: $this->filled('customer_profile_id') ? $this->integer('customer_profile_id') : null,
            from: $this->filled('from') ? (string) $this->string('from') : null,
            to: $this->filled('to') ? (string) $this->string('to') : null,
            search: $this->filled('search') ? trim((string) $this->string('search')) : null,
            perPage: $this->filled('per_page') ? $this->integer('per_page') : 20,
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
