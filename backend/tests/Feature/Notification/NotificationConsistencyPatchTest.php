<?php

namespace Tests\Feature\Notification;

use App\Actions\Notification\ArchiveNotificationAction;
use App\Actions\Notification\CreateNotificationAction;
use App\Actions\Notification\MarkNotificationReadAction;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Events\Notification\NotificationRequested;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationConsistencyPatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_app_processing_automatically_transitions_to_delivered(): void
    {
        $admin = Admin::factory()->create();
        $this->createTemplate('consistency_in_app', NotificationType::System, NotificationChannel::InApp);

        event(NotificationRequested::make(
            recipient: $admin,
            templateKey: 'consistency_in_app',
        ));

        $notification = Notification::query()->firstOrFail();

        $this->assertSame(NotificationStatus::Delivered, $notification->status);
        $this->assertNotNull($notification->sent_at);
        $this->assertNotNull($notification->delivered_at);
    }

    public function test_email_and_sms_remain_sent_for_future_provider_callbacks(): void
    {
        $profile = CustomerProfile::factory()->create();
        $this->createTemplate('consistency_email', NotificationType::Order, NotificationChannel::Email, [
            'subject' => 'Order',
        ]);

        event(NotificationRequested::make(
            recipient: $profile,
            templateKey: 'consistency_email',
        ));

        $email = Notification::query()->where('channel', NotificationChannel::Email->value)->firstOrFail();

        $this->assertSame(NotificationStatus::Sent, $email->status);
        $this->assertNotNull($email->sent_at);
        $this->assertNull($email->delivered_at);
    }

    public function test_read_flow_works_after_automatic_in_app_delivery(): void
    {
        $admin = Admin::factory()->create();
        $this->createTemplate('consistency_read', NotificationType::Inventory, NotificationChannel::InApp);

        event(NotificationRequested::make(
            recipient: $admin,
            templateKey: 'consistency_read',
        ));

        $notification = Notification::query()->firstOrFail();
        $this->assertSame(NotificationStatus::Delivered, $notification->status);

        $read = $this->app->make(MarkNotificationReadAction::class)->handle($admin, $notification->id);

        $this->assertSame(NotificationStatus::Read, $read->status);
        $this->assertNotNull($read->read_at);
    }

    public function test_unread_count_endpoint_returns_owner_scoped_count(): void
    {
        [$user, $profile] = $this->customerWithProfile();
        $other = CustomerProfile::factory()->create();

        Notification::factory()->forCustomerProfile($profile)->delivered()->count(2)->create();
        Notification::factory()->forCustomerProfile($profile)->read()->create();
        Notification::factory()->forCustomerProfile($profile)->sent()->create();
        Notification::factory()->forCustomerProfile($other)->delivered()->create();

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('message', 'Unread notification count retrieved successfully.')
            ->assertJsonPath('data.unread_count', 3);
    }

    public function test_database_rejects_duplicate_event_id_for_same_recipient_and_channel(): void
    {
        $admin = Admin::factory()->create();

        Notification::factory()->forAdmin($admin)->create([
            'channel' => NotificationChannel::InApp,
            'event_id' => 'evt-unique-guard',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Notification::factory()->forAdmin($admin)->create([
            'channel' => NotificationChannel::InApp,
            'event_id' => 'evt-unique-guard',
        ]);
    }

    public function test_create_action_is_idempotent_under_unique_event_constraint(): void
    {
        $admin = Admin::factory()->create();
        $action = $this->app->make(CreateNotificationAction::class);

        $first = $action->handle(
            recipient: $admin,
            type: NotificationType::System,
            channels: [NotificationChannel::InApp],
            title: 'First',
            message: 'Message',
            eventId: 'evt-create-idempotent',
        );

        $second = $action->handle(
            recipient: $admin,
            type: NotificationType::System,
            channels: [NotificationChannel::InApp],
            title: 'Second',
            message: 'Message',
            eventId: 'evt-create-idempotent',
        );

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
        $this->assertSame(1, Notification::query()->where('event_id', 'evt-create-idempotent')->count());
        $this->assertDatabaseHas('notifications', [
            'event_id' => 'evt-create-idempotent',
            'title' => 'First',
        ]);
    }

    public function test_same_event_id_may_exist_on_different_channels(): void
    {
        $admin = Admin::factory()->create();

        Notification::factory()->forAdmin($admin)->create([
            'channel' => NotificationChannel::InApp,
            'event_id' => 'evt-multi-channel',
        ]);
        Notification::factory()->forAdmin($admin)->create([
            'channel' => NotificationChannel::Email,
            'event_id' => 'evt-multi-channel',
        ]);

        $this->assertSame(2, Notification::query()->where('event_id', 'evt-multi-channel')->count());
    }

    public function test_archived_read_notification_remains_supported_after_consistency_patch(): void
    {
        $admin = Admin::factory()->create();
        $this->createTemplate('consistency_archive', NotificationType::System, NotificationChannel::InApp);

        event(NotificationRequested::make(
            recipient: $admin,
            templateKey: 'consistency_archive',
        ));

        $notification = Notification::query()->firstOrFail();
        $read = $this->app->make(MarkNotificationReadAction::class)->handle($admin, $notification->id);
        $archived = $this->app->make(ArchiveNotificationAction::class)->handle($read);

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
        $this->assertSame($notification->id, $archived->original_notification_id);
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTemplate(
        string $key,
        NotificationType $type,
        NotificationChannel $channel,
        array $overrides = [],
    ): NotificationTemplate {
        return NotificationTemplate::factory()->create([
            'template_key' => $key,
            'type' => $type,
            'channel' => $channel,
            'title' => $overrides['title'] ?? 'Hello',
            'message' => $overrides['message'] ?? 'Body',
            'subject' => $overrides['subject'] ?? null,
        ]);
    }
}
