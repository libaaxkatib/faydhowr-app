<?php

namespace Tests\Feature\Api\V1\Devices;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_a_new_device(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/devices', [
                'device_id' => 'device-abc-123',
                'platform' => 'android',
                'push_token' => 'fcm-token-1',
                'app_version' => '1.4.0',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Device registered successfully.')
            ->assertJsonPath('data.device_id', 'device-abc-123')
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.push_token', 'fcm-token-1')
            ->assertJsonPath('data.app_version', '1.4.0')
            ->assertJsonPath('data.is_active', true);

        $this->assertNotNull($response->json('data.last_seen_at'));

        $this->assertDatabaseHas('customer_devices', [
            'user_id' => $user->id,
            'device_id' => 'device-abc-123',
            'platform' => 'android',
            'push_token' => 'fcm-token-1',
            'app_version' => '1.4.0',
            'is_active' => true,
        ]);
    }

    public function test_registering_the_same_device_again_updates_the_existing_record(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/devices', [
            'device_id' => 'device-abc-123',
            'platform' => 'android',
            'push_token' => 'fcm-token-1',
            'app_version' => '1.4.0',
        ])->assertCreated();

        $response = $this->withToken($token)->postJson('/api/v1/devices', [
            'device_id' => 'device-abc-123',
            'platform' => 'android',
            'push_token' => 'fcm-token-2',
            'app_version' => '1.5.0',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Device updated successfully.')
            ->assertJsonPath('data.push_token', 'fcm-token-2')
            ->assertJsonPath('data.app_version', '1.5.0');

        $this->assertSame(1, $user->devices()->count());
        $this->assertDatabaseHas('customer_devices', [
            'user_id' => $user->id,
            'device_id' => 'device-abc-123',
            'push_token' => 'fcm-token-2',
            'app_version' => '1.5.0',
        ]);
    }

    public function test_the_same_device_id_can_belong_to_different_users(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this
            ->withToken($firstUser->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/devices', [
                'device_id' => 'shared-device',
                'platform' => 'ios',
            ])
            ->assertCreated();

        $this->app['auth']->forgetGuards();

        $this
            ->withToken($secondUser->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/devices', [
                'device_id' => 'shared-device',
                'platform' => 'ios',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('customer_devices', 2);
    }

    public function test_push_token_and_app_version_are_optional(): void
    {
        $user = User::factory()->create();

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/devices', [
                'device_id' => 'device-minimal',
                'platform' => 'ios',
            ])
            ->assertCreated()
            ->assertJsonPath('data.push_token', null)
            ->assertJsonPath('data.app_version', null);
    }

    public function test_device_registration_validates_input(): void
    {
        $user = User::factory()->create();

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/devices', [
                'platform' => 'windows',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['device_id', 'platform']]);
    }

    public function test_device_registration_requires_authentication(): void
    {
        $this
            ->postJson('/api/v1/devices', [
                'device_id' => 'device-abc-123',
                'platform' => 'android',
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }
}
