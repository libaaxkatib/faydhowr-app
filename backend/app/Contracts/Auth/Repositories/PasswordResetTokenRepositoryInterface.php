<?php

namespace App\Contracts\Auth\Repositories;

use App\Models\PasswordResetToken;
use App\Models\User;

interface PasswordResetTokenRepositoryInterface
{
    public function findUsableForUser(User $user): ?PasswordResetToken;

    public function invalidateForUser(User $user): void;

    public function create(User $user, string $tokenHash, \DateTimeInterface $expiresAt): PasswordResetToken;

    public function markUsed(PasswordResetToken $token): PasswordResetToken;
}
