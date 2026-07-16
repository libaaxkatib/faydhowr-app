<?php

namespace App\Actions\Auth;

use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RegisterCustomerAction
{
    /**
     * @param  array{name: string, email: string, password: string}  $attributes
     * @return array{user: User, access_token: string, token_type: string}
     */
    public function handle(array $attributes): array
    {
        return DB::transaction(function () use ($attributes): array {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => Str::lower($attributes['email']),
                'password' => Hash::make($attributes['password']),
            ]);

            $profile = new CustomerProfile([
                'full_name' => $user->name,
                'preferred_language' => 'so',
            ]);
            $profile->customer_number = sprintf('CUS-%s-%06d', now()->format('Y'), $user->id);
            $profile->classification = 'lead';

            $user->customerProfile()->save($profile);

            return [
                'user' => $user,
                'access_token' => $user->createToken('customer-mobile')->plainTextToken,
                'token_type' => 'Bearer',
            ];
        });
    }
}
