<?php

namespace App\Repositories\Auth;

use App\Contracts\Auth\Repositories\PasswordResetTokenRepositoryInterface;
use App\Models\PasswordResetToken;
use App\Models\User;

class PasswordResetTokenRepository implements PasswordResetTokenRepositoryInterface
{
    public function findUsableForUser(User $user): ?PasswordResetToken
    {
        return PasswordResetToken::query()
            ->where('subject_type', PasswordResetToken::SUBJECT_USER)
            ->where('subject_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();
    }

    public function invalidateForUser(User $user): void
    {
        PasswordResetToken::query()
            ->where('subject_type', PasswordResetToken::SUBJECT_USER)
            ->where('subject_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }

    public function create(User $user, string $tokenHash, \DateTimeInterface $expiresAt): PasswordResetToken
    {
        return PasswordResetToken::query()->create([
            'subject_type' => PasswordResetToken::SUBJECT_USER,
            'subject_id' => $user->id,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);
    }

    public function markUsed(PasswordResetToken $token): PasswordResetToken
    {
        $token->used_at = now();
        $token->save();

        return $token;
    }
}
