<?php

namespace App\Http\Requests\Api\V1\Admin\Customers;

use App\DataTransferObjects\Customer\CustomerSearchFiltersData;
use App\Enums\Customer\CustomerStatus;
use Illuminate\Validation\Rule;

class ListCustomersRequest extends CustomersFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:191'],
            'status' => ['sometimes', 'nullable', Rule::in(CustomerStatus::values())],
            'registered_from' => ['sometimes', 'nullable', 'date'],
            'registered_to' => ['sometimes', 'nullable', 'date'],
            'last_login_from' => ['sometimes', 'nullable', 'date'],
            'last_login_to' => ['sometimes', 'nullable', 'date'],
            'country' => ['sometimes', 'nullable', 'string', 'max:2'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'district' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'string', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function toFilters(): CustomerSearchFiltersData
    {
        $validated = $this->validated();

        return new CustomerSearchFiltersData(
            search: $validated['search'] ?? null,
            status: $validated['status'] ?? null,
            registeredFrom: $validated['registered_from'] ?? null,
            registeredTo: $validated['registered_to'] ?? null,
            lastLoginFrom: $validated['last_login_from'] ?? null,
            lastLoginTo: $validated['last_login_to'] ?? null,
            country: $validated['country'] ?? null,
            state: $validated['state'] ?? null,
            district: $validated['district'] ?? null,
            sort: $validated['sort'] ?? '-registered_at',
            page: (int) ($validated['page'] ?? 1),
            perPage: (int) ($validated['per_page'] ?? 15),
        );
    }
}
