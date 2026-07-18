<?php

namespace App\Http\Requests\Api\V1\Admin\Quotations;

use App\DataTransferObjects\Quotation\AdminQuotationFiltersData;
use App\Enums\QuotationStatus;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListAdminQuotationsRequest extends FormRequest
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
            'status' => ['sometimes', Rule::enum(QuotationStatus::class)],
            'assigned_admin_id' => ['sometimes', 'integer', 'min:1'],
            'customer_profile_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function toFilters(): AdminQuotationFiltersData
    {
        return new AdminQuotationFiltersData(
            status: $this->filled('status') ? QuotationStatus::from((string) $this->string('status')) : null,
            assignedAdminId: $this->filled('assigned_admin_id') ? $this->integer('assigned_admin_id') : null,
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
