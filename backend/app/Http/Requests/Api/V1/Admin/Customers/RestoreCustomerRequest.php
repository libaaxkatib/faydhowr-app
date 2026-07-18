<?php

namespace App\Http\Requests\Api\V1\Admin\Customers;

use App\DataTransferObjects\Customer\RestoreCustomerData;
use App\Enums\Customer\CustomerStatus;
use Illuminate\Validation\Rule;

class RestoreCustomerRequest extends CustomersFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(CustomerStatus::restoreValues())],
        ];
    }

    public function toData(): RestoreCustomerData
    {
        return new RestoreCustomerData(
            CustomerStatus::from((string) $this->validated('status')),
        );
    }
}
