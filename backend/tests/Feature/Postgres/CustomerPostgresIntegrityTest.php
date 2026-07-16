<?php

namespace Tests\Feature\Postgres;

use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerPostgresIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This integration test requires PostgreSQL.');
        }
    }

    public function test_customer_profile_check_constraints_are_enforced(): void
    {
        $this->expectException(QueryException::class);

        CustomerProfile::factory()->create([
            'preferred_language' => 'fr',
        ]);
    }

    public function test_only_one_active_default_address_can_exist_per_profile(): void
    {
        $profile = CustomerProfile::factory()->create();

        CustomerAddress::factory()
            ->for($profile, 'customerProfile')
            ->create([
                'is_active' => true,
                'is_default' => true,
            ]);

        $this->expectException(QueryException::class);

        CustomerAddress::factory()
            ->for($profile, 'customerProfile')
            ->create([
                'is_active' => true,
                'is_default' => true,
            ]);
    }

    public function test_customer_profile_user_link_is_unique(): void
    {
        $user = User::factory()->create();

        CustomerProfile::factory()->for($user)->create();

        $this->expectException(QueryException::class);

        CustomerProfile::factory()->for($user)->create();
    }
}
