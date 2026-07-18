<?php

namespace App\DataTransferObjects\Customer;

use App\Enums\Customer\CustomerGender;

readonly class UpdateCustomerData
{
    /**
     * @param  list<string>|null  $tags
     */
    public function __construct(
        public ?string $fullName = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?CustomerGender $gender = null,
        public ?string $dateOfBirth = null,
        public ?string $preferredLanguage = null,
        public ?array $tags = null,
        public ?string $avatarUrl = null,
        public bool $clearEmail = false,
        public bool $clearGender = false,
        public bool $clearDateOfBirth = false,
        public bool $clearTags = false,
        public bool $clearAvatarUrl = false,
    ) {}
}
