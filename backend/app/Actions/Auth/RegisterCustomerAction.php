<?php

namespace App\Actions\Auth;

use App\Contracts\Customer\Services\CustomerActivityServiceInterface;
use App\Contracts\Dashboard\DashboardCacheInvalidatorInterface;
use App\Enums\Customer\ActivityType;
use App\Enums\Customer\CustomerStatus;
use App\Enums\UserStatus;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Support\Customer\CustomerCodeGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterCustomerAction
{
    public function __construct(
        private DashboardCacheInvalidatorInterface $dashboardCache,
        private CustomerCodeGenerator $codes,
        private CustomerActivityServiceInterface $activities,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string}  $attributes
     * @return array{user: User, access_token: string, token_type: string}
     */
    public function handle(array $attributes): array
    {
        $registration = DB::transaction(function () use ($attributes): array {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => Str::lower($attributes['email']),
                'password' => Hash::make($attributes['password']),
                'status' => UserStatus::Active,
            ]);

            $profile = new CustomerProfile([
                'full_name' => $user->name,
                'preferred_language' => 'so',
            ]);
            $profile->customer_number = $this->codes->next();
            $profile->classification = 'lead';
            $profile->status = CustomerStatus::Active;

            $user->customerProfile()->save($profile);

            $this->activities->record($profile, ActivityType::Registration, 'Account registered');

            return [
                'user' => $user,
                'access_token' => $user->createToken('customer-mobile')->plainTextToken,
                'token_type' => 'Bearer',
            ];
        });

        $this->dashboardCache->invalidate();

        return $registration;
    }
}
