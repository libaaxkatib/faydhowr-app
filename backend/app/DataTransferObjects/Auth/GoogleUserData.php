<?php

namespace App\DataTransferObjects\Auth;

final readonly class GoogleUserData
{
    public function __construct(
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name,
    ) {}
}
