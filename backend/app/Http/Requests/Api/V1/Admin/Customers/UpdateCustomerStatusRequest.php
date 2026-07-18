<?php

namespace App\Http\Requests\Api\V1\Admin\Customers;

use App\DataTransferObjects\Customer\UpdateCustomerStatusData;
use App\Enums\Customer\CustomerStatus;
use Illuminate\Validation\Rule;

class UpdateCustomerStatusRequest extends CustomersFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(CustomerStatus::persistedValues())],
        ];
    }

    public function toData(): UpdateCustomerStatusData
    {
        return new UpdateCustomerStatusData(
            CustomerStatus::from((string) $this->validated('status')),
        );
    }
}
