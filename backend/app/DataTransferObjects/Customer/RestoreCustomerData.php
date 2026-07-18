<?php

namespace App\DataTransferObjects\Customer;

use App\Enums\Customer\CustomerStatus;

readonly class RestoreCustomerData
{
    public function __construct(public CustomerStatus $status) {}
}
