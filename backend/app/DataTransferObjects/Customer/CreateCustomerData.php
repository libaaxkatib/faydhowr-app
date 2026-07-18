<?php

namespace App\DataTransferObjects\Customer;

use App\Enums\Customer\CustomerGender;

readonly class CreateCustomerData
{
    /**
     * @param  list<string>|null  $tags
     */
    public function __construct(
        public string $fullName,
        public string $phone,
        public string $password,
        public ?string $email = null,
        public ?CustomerGender $gender = null,
        public ?string $dateOfBirth = null,
        public string $preferredLanguage = 'so',
        public ?array $tags = null,
        public ?string $avatarUrl = null,
    ) {}
}
