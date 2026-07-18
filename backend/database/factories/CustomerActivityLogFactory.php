<?php

namespace Database\Factories;

use App\Enums\Customer\ActivityType;
use App\Models\CustomerActivityLog;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerActivityLog>
 */
class CustomerActivityLogFactory extends Factory
{
    protected $model = CustomerActivityLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_profile_id' => CustomerProfile::factory(),
            'event_type' => ActivityType::Registration,
            'description' => 'Account registered',
            'subject_type' => null,
            'subject_id' => null,
            'metadata' => null,
            'created_at' => now(),
        ];
    }
}
