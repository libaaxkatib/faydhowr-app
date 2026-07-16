<?php

namespace Tests\Feature\Notification;

use App\Actions\Notification\ResolveNotificationChannelsAction;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Events\Notification\NotificationRequested;
use App\Jobs\Notification\ProcessNotificationJob;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class NotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_receives_default_preferences_without_persisting_rows(): void
    {
        [$user] = $this->customerWithProfile();

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/notification-preferences');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(count(NotificationType::cases()), 'data')
            ->assertJsonPath('data.0.notification_type', NotificationType::Booking->value)
            ->assertJsonPath('data.0.in_app', true)
            ->assertJsonPath('data.0.email', true)
            ->assertJsonPath('data.0.sms', false)
            ->assertJsonMissingPath('data.0.recipient_type')
            ->assertJsonMissingPath('data.0.recipient_id');

        $this->assertDatabaseCount('notification_preferences', 0);
    }

    public function test_customer_can_update_preferences_atomically(): void
    {
        [$user, $profile] = $this->customerWithProfile();
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $payload = [
            'preferences' => [
                [
                    'notification_type' => NotificationType::Payment->value,
                    'in_app' => true,
                    'email' => false,
                    'sms' => true,
                ],
                [
                    'notification_type' => NotificationType::Booking->value,
                    'in_app' => false,
                    'email' => true,
                    'sms' => false,
                ],
            ],
        ];

        $this
            ->withToken($token)
            ->putJson('/api/v1/notification-preferences', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Notification preferences updated successfully.');

        $this->assertDatabaseCount('notification_preferences', 2);
        $this->assertDatabaseHas('notification_preferences', [
            'recipient_type' => CustomerProfile::class,
            'recipient_id' => $profile->id,
            'notification_type' => NotificationType::Payment->value,
            'in_app' => 1,
            'email' => 0,
            'sms' => 1,
        ]);

        $this
            ->withToken($token)
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [
                    [
                        'notification_type' => NotificationType::System->value,
                        'in_app' => true,
                        'email' => true,
                        'sms' => false,
                    ],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseCount('notification_preferences', 1);
        $this->assertDatabaseHas('notification_preferences', [
            'notification_type' => NotificationType::System->value,
        ]);
        $this->assertDatabaseMissing('notification_preferences', [
            'notification_type' => NotificationType::Payment->value,
        ]);
    }

    public function test_admin_can_manage_own_preferences(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/notification-preferences')
            ->assertOk()
            ->assertJsonCount(count(NotificationType::cases()), 'data');

        $this
            ->withToken($token)
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [
                    [
                        'notification_type' => NotificationType::Inventory->value,
                        'in_app' => true,
                        'email' => false,
                        'sms' => false,
                    ],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('notification_preferences', [
            'recipient_type' => Admin::class,
            'recipient_id' => $admin->id,
            'notification_type' => NotificationType::Inventory->value,
            'email' => 0,
        ]);
    }

    public function test_resolve_channels_uses_defaults_and_stored_preferences(): void
    {
        $admin = Admin::factory()->create();
        $resolver = app(ResolveNotificationChannelsAction::class);

        $this->assertSame(
            [
                NotificationChannel::InApp,
                NotificationChannel::Email,
            ],
            $resolver->handle($admin, NotificationType::Payment),
        );

        NotificationPreference::factory()->forAdmin($admin)->create([
            'notification_type' => NotificationType::Payment,
            'in_app' => false,
            'email' => true,
            'sms' => true,
        ]);

        $this->assertSame(
            [
                NotificationChannel::Email,
                NotificationChannel::Sms,
            ],
            $resolver->handle($admin, NotificationType::Payment),
        );
    }

    public function test_notification_dispatch_skips_disabled_channels(): void
    {
        Bus::fake([ProcessNotificationJob::class]);

        $profile = CustomerProfile::factory()->create();
        NotificationTemplate::factory()->create([
            'template_key' => 'payment_email',
            'type' => NotificationType::Payment,
            'channel' => NotificationChannel::Email,
            'title' => 'Payment update',
            'message' => 'Payment {{payment_number}} received.',
        ]);
        NotificationPreference::factory()->forCustomerProfile($profile)->create([
            'notification_type' => NotificationType::Payment,
            'in_app' => true,
            'email' => false,
            'sms' => false,
        ]);

        event(NotificationRequested::make(
            recipient: $profile,
            templateKey: 'payment_email',
            variables: ['payment_number' => 'PAY-1'],
        ));

        $this->assertDatabaseCount('notifications', 0);
        Bus::assertNothingDispatched();
    }

    public function test_notification_dispatch_persists_when_channel_enabled(): void
    {
        Bus::fake([ProcessNotificationJob::class]);

        $profile = CustomerProfile::factory()->create();
        NotificationTemplate::factory()->create([
            'template_key' => 'payment_in_app',
            'type' => NotificationType::Payment,
            'channel' => NotificationChannel::InApp,
            'title' => 'Payment update',
            'message' => 'Payment {{payment_number}} received.',
        ]);
        NotificationPreference::factory()->forCustomerProfile($profile)->create([
            'notification_type' => NotificationType::Payment,
            'in_app' => true,
            'email' => false,
            'sms' => false,
        ]);

        event(NotificationRequested::make(
            recipient: $profile,
            templateKey: 'payment_in_app',
            variables: ['payment_number' => 'PAY-2'],
        ));

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(NotificationChannel::InApp, Notification::query()->firstOrFail()->channel);
        Bus::assertDispatched(ProcessNotificationJob::class);
    }

    public function test_update_preferences_validates_payload(): void
    {
        [$user] = $this->customerWithProfile();
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $this
            ->withToken($token)
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [
                    [
                        'notification_type' => 'invalid',
                        'in_app' => true,
                        'email' => true,
                        'sms' => false,
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this
            ->withToken($token)
            ->putJson('/api/v1/notification-preferences', [
                'preferences' => [
                    [
                        'notification_type' => NotificationType::Payment->value,
                        'in_app' => true,
                        'email' => true,
                        'sms' => false,
                    ],
                    [
                        'notification_type' => NotificationType::Payment->value,
                        'in_app' => false,
                        'email' => false,
                        'sms' => true,
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/v1/notification-preferences')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->putJson('/api/v1/notification-preferences', ['preferences' => []])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    /**
     * @return array{0: User, 1: CustomerProfile}
     */
    private function customerWithProfile(): array
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);

        return [$user, $profile];
    }
}
