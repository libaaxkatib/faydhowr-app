<?php

namespace App\Support\Auth;

use App\Contracts\Customer\Services\CustomerActivityServiceInterface;
use App\Enums\Customer\ActivityType;
use App\Enums\Customer\CustomerStatus;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Shared status gates and login completion for every customer login method
 * (email, phone OTP, Google). Gates enforce users.status (identity) and
 * customer_profiles.status (business) per the approved status split.
 */
class CustomerAuthenticator
{
    public function __construct(private CustomerActivityServiceInterface $activities) {}

    public function passesGates(User $user): bool
    {
        if (in_array($user->status, [UserStatus::Suspended, UserStatus::Deactivated], true)) {
            return false;
        }

        $profile = $user->customerProfile;

        if ($profile === null || $profile->trashed()) {
            return false;
        }

        return ! in_array($profile->status, [CustomerStatus::Blocked, CustomerStatus::Inactive], true);
    }

    /**
     * @return array{user: User, access_token: string, token_type: string}
     */
    public function completeLogin(User $user, string $description = 'Customer login'): array
    {
        $user->forceFill(['last_login_at' => now()])->save();

        $this->activities->record($user->customerProfile, ActivityType::Login, $description);

        return [
            'user' => $user,
            'access_token' => $user->createToken('customer-mobile')->plainTextToken,
            'token_type' => 'Bearer',
        ];
    }
}
