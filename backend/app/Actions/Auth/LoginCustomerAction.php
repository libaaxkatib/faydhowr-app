<?php

namespace App\Actions\Auth;

use App\Contracts\Customer\Services\CustomerActivityServiceInterface;
use App\Enums\Customer\ActivityType;
use App\Enums\Customer\CustomerStatus;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoginCustomerAction
{
    public function __construct(private CustomerActivityServiceInterface $activities) {}

    /**
     * @param  array{email: string, password: string}  $credentials
     * @return array{user: User, access_token: string, token_type: string}|null
     */
    public function handle(array $credentials): ?array
    {
        $user = User::query()
            ->with('customerProfile')
            ->where('email', Str::lower($credentials['email']))
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        if (in_array($user->status, [UserStatus::Suspended, UserStatus::Deactivated], true)) {
            return null;
        }

        $profile = $user->customerProfile;

        if ($profile === null || $profile->trashed()) {
            return null;
        }

        // Business status gates login for BLOCKED / INACTIVE (customer_profiles.status only).
        if (in_array($profile->status, [CustomerStatus::Blocked, CustomerStatus::Inactive], true)) {
            return null;
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $this->activities->record($profile, ActivityType::Login, 'Customer login');

        return [
            'user' => $user,
            'access_token' => $user->createToken('customer-mobile')->plainTextToken,
            'token_type' => 'Bearer',
        ];
    }
}
