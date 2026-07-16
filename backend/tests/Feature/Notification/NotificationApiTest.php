<?php

namespace Tests\Feature\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_list_own_notifications_newest_first(): void
    {
        [$user, $profile] = $this->customerWithProfile();
        $other = CustomerProfile::factory()->create();

        $older = Notification::factory()->forCustomerProfile($profile)->create([
            'title' => 'Older',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        $newer = Notification::factory()->forCustomerProfile($profile)->create([
            'title' => 'Newer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Notification::factory()->forCustomerProfile($other)->create([
            'title' => 'Other owner',
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/notifications');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Notifications retrieved successfully.')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.id', $newer->id)
            ->assertJsonPath('data.items.1.id', $older->id)
            ->assertJsonMissingPath('data.items.0.recipient_type')
            ->assertJsonMissingPath('data.items.0.recipient_id');
    }

    public function test_admin_can_list_own_notifications(): void
    {
        $admin = Admin::factory()->create();
        Notification::factory()->forAdmin($admin)->count(2)->create();
        Notification::factory()->forAdmin()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_list_supports_status_type_and_channel_filters(): void
    {
        [$user, $profile] = $this->customerWithProfile();

        Notification::factory()->forCustomerProfile($profile)->create([
            'type' => NotificationType::Booking,
            'channel' => NotificationChannel::InApp,
            'status' => NotificationStatus::Pending,
        ]);
        Notification::factory()->forCustomerProfile($profile)->sent()->create([
            'type' => NotificationType::Payment,
            'channel' => NotificationChannel::Email,
        ]);
        Notification::factory()->forCustomerProfile($profile)->create([
            'type' => NotificationType::Booking,
            'channel' => NotificationChannel::Sms,
            'status' => NotificationStatus::Pending,
        ]);

        $token = $user->createToken('customer-mobile')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/notifications?type=booking&channel=in_app&status=pending')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.type', NotificationType::Booking->value)
            ->assertJsonPath('data.items.0.channel', NotificationChannel::InApp->value);
    }

    public function test_customer_can_view_own_notification_detail(): void
    {
        [$user, $profile] = $this->customerWithProfile();
        $notification = Notification::factory()->forCustomerProfile($profile)->create([
            'title' => 'Detail title',
            'message' => 'Detail message',
            'data' => ['ref' => 'BK-1'],
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson("/api/v1/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Notification retrieved successfully.')
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.title', 'Detail title')
            ->assertJsonPath('data.message', 'Detail message')
            ->assertJsonPath('data.data.ref', 'BK-1')
            ->assertJsonMissingPath('data.recipient_type')
            ->assertJsonMissingPath('data.recipient_id')
            ->assertJsonPath('data.sent_at', null)
            ->assertJsonPath('data.processing_started_at', null)
            ->assertJsonPath('data.delivered_at', null)
            ->assertJsonPath('data.failed_at', null);
    }

    public function test_customer_can_mark_one_notification_as_read(): void
    {
        [$user, $profile] = $this->customerWithProfile();
        $notification = Notification::factory()->forCustomerProfile($profile)->delivered()->create();

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->patchJson("/api/v1/notifications/{$notification->id}/read");

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Notification marked as read.')
            ->assertJsonPath('data.status', NotificationStatus::Read->value);

        $this->assertNotNull($response->json('data.read_at'));
        $this->assertNotNull($response->json('data.delivered_at'));

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
            'status' => NotificationStatus::Read->value,
        ]);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_mark_read_rejects_non_delivered_notifications(): void
    {
        [$user, $profile] = $this->customerWithProfile();
        $notification = Notification::factory()->forCustomerProfile($profile)->sent()->create();

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'INVALID_NOTIFICATION_STATUS_TRANSITION');
    }

    public function test_mark_read_is_idempotent_and_preserves_original_read_at(): void
    {
        [$user, $profile] = $this->customerWithProfile();
        $readAt = now()->subHour()->startOfSecond();
        $notification = Notification::factory()->forCustomerProfile($profile)->read()->create([
            'read_at' => $readAt,
        ]);

        $token = $user->createToken('customer-mobile')->plainTextToken;

        $this
            ->withToken($token)
            ->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.status', NotificationStatus::Read->value);

        $this
            ->withToken($token)
            ->patchJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.status', NotificationStatus::Read->value);

        $this->assertTrue($notification->fresh()->read_at->equalTo($readAt));
    }

    public function test_customer_can_mark_all_unread_notifications_as_read(): void
    {
        [$user, $profile] = $this->customerWithProfile();
        Notification::factory()->forCustomerProfile($profile)->delivered()->count(2)->create();
        Notification::factory()->forCustomerProfile($profile)->sent()->create();
        Notification::factory()->forCustomerProfile($profile)->read()->create();
        Notification::factory()->forAdmin()->create();

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->patchJson('/api/v1/notifications/read-all');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Notifications marked as read.')
            ->assertJsonPath('data.updated_count', 2);

        $this->assertSame(
            3,
            Notification::query()
                ->where('recipient_type', CustomerProfile::class)
                ->where('recipient_id', $profile->id)
                ->where('status', NotificationStatus::Read->value)
                ->count(),
        );

        $this->assertSame(
            1,
            Notification::query()
                ->where('recipient_type', CustomerProfile::class)
                ->where('recipient_id', $profile->id)
                ->where('status', NotificationStatus::Sent->value)
                ->count(),
        );
    }

    public function test_non_owned_notification_returns_not_found(): void
    {
        [$user] = $this->customerWithProfile();
        $foreign = Notification::factory()->forCustomerProfile()->create();

        $token = $user->createToken('customer-mobile')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson("/api/v1/notifications/{$foreign->id}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOTIFICATION_NOT_FOUND');

        $this
            ->withToken($token)
            ->patchJson("/api/v1/notifications/{$foreign->id}/read")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOTIFICATION_NOT_FOUND');
    }

    public function test_admin_cannot_access_customer_notification(): void
    {
        $admin = Admin::factory()->create();
        $customerNotification = Notification::factory()->forCustomerProfile()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson("/api/v1/notifications/{$customerNotification->id}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOTIFICATION_NOT_FOUND');
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/v1/notifications')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->patchJson('/api/v1/notifications/read-all')
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
