<?php

namespace App\DataTransferObjects\Customer;

use App\Enums\Customer\CustomerStatus;

readonly class UpdateCustomerStatusData
{
    public function __construct(public CustomerStatus $status) {}
}
