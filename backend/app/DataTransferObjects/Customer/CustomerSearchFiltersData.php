<?php

namespace App\DataTransferObjects\Customer;

readonly class CustomerSearchFiltersData
{
    public function __construct(
        public ?string $search = null,
        public ?string $status = null,
        public ?string $registeredFrom = null,
        public ?string $registeredTo = null,
        public ?string $lastLoginFrom = null,
        public ?string $lastLoginTo = null,
        public ?string $country = null,
        public ?string $state = null,
        public ?string $district = null,
        public string $sort = '-registered_at',
        public int $page = 1,
        public int $perPage = 15,
    ) {}
}
