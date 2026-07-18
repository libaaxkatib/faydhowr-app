<?php

namespace App\Http\Requests\Api\V1\Admin\Bookings;

use App\DataTransferObjects\Booking\AdminBookingFiltersData;
use App\Enums\BookingStatus;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListAdminBookingsRequest extends FormRequest
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
            'status' => ['sometimes', Rule::enum(BookingStatus::class)],
            'service_id' => ['sometimes', 'integer', 'min:1'],
            'customer_profile_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'search' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function toFilters(): AdminBookingFiltersData
    {
        return new AdminBookingFiltersData(
            status: $this->filled('status') ? BookingStatus::from((string) $this->string('status')) : null,
            serviceId: $this->filled('service_id') ? $this->integer('service_id') : null,
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
