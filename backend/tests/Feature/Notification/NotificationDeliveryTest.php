<?php

namespace Tests\Feature\Notification;

use App\Contracts\Notification\NotificationChannelInterface;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Events\Notification\NotificationRequested;
use App\Jobs\Notification\ProcessNotificationJob;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Services\Notification\NotificationChannelManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_requested_dispatches_process_notification_jobs(): void
    {
        Bus::fake([ProcessNotificationJob::class]);

        $admin = Admin::factory()->create();
        $this->createTemplate('queued_notice', NotificationType::System, NotificationChannel::InApp);

        event(NotificationRequested::make(
            recipient: $admin,
            templateKey: 'queued_notice',
            variables: ['customer_name' => 'Ops', 'booking_number' => 'BK-1', 'date' => '2026-07-16'],
        ));

        Bus::assertDispatchedTimes(ProcessNotificationJob::class, 1);

        $id = Notification::query()->value('id');

        Bus::assertDispatched(
            ProcessNotificationJob::class,
            fn (ProcessNotificationJob $job): bool => $job->notificationId === $id,
        );
    }

    public function test_in_app_channel_marks_notification_as_delivered(): void
    {
        $admin = Admin::factory()->create();
        $this->createTemplate('in_app_notice', NotificationType::Inventory, NotificationChannel::InApp, [
            'title' => 'In-app notice',
            'message' => 'Delivered in app.',
        ]);

        event(NotificationRequested::make(
            recipient: $admin,
            templateKey: 'in_app_notice',
        ));

        $notification = Notification::query()->firstOrFail();

        $this->assertSame(NotificationChannel::InApp, $notification->channel);
        $this->assertSame(NotificationStatus::Delivered, $notification->status);
        $this->assertNotNull($notification->processing_started_at);
        $this->assertNotNull($notification->sent_at);
        $this->assertNotNull($notification->delivered_at);
        $this->assertNull($notification->read_at);
    }

    public function test_email_channel_persists_as_sent_without_external_provider(): void
    {
        $profile = CustomerProfile::factory()->create();
        $this->createTemplate('order_email', NotificationType::Order, NotificationChannel::Email, [
            'title' => 'Order email',
            'message' => 'Your order was placed.',
            'subject' => 'Order update',
        ]);

        event(NotificationRequested::make(
            recipient: $profile,
            templateKey: 'order_email',
        ));

        $notification = Notification::query()->firstOrFail();

        $this->assertSame(NotificationChannel::Email, $notification->channel);
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertNotNull($notification->processing_started_at);
        $this->assertNotNull($notification->sent_at);
    }

    public function test_sms_channel_persists_as_sent_without_external_provider(): void
    {
        $profile = CustomerProfile::factory()->create();
        NotificationPreference::factory()->forCustomerProfile($profile)->create([
            'notification_type' => NotificationType::Booking,
            'in_app' => true,
            'email' => true,
            'sms' => true,
        ]);
        $this->createTemplate('booking_sms', NotificationType::Booking, NotificationChannel::Sms, [
            'title' => 'Booking SMS',
            'message' => 'Your booking was updated.',
        ]);

        event(NotificationRequested::make(
            recipient: $profile,
            templateKey: 'booking_sms',
        ));

        $notification = Notification::query()->firstOrFail();

        $this->assertSame(NotificationChannel::Sms, $notification->channel);
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertNotNull($notification->processing_started_at);
        $this->assertNotNull($notification->sent_at);
    }

    public function test_processing_failure_marks_notification_as_failed(): void
    {
        $admin = Admin::factory()->create();
        $notification = Notification::factory()->forAdmin($admin)->create([
            'channel' => NotificationChannel::InApp,
            'status' => NotificationStatus::Pending,
        ]);

        $manager = new NotificationChannelManager;
        $manager->register('in_app', new class implements NotificationChannelInterface
        {
            public function send(Notification $notification): void
            {
                throw new RuntimeException('Channel unavailable.');
            }
        });
        $this->app->instance(NotificationChannelManager::class, $manager);

        ProcessNotificationJob::dispatchSync($notification->id);

        $notification->refresh();

        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertNotNull($notification->processing_started_at);
        $this->assertNotNull($notification->failed_at);
        $this->assertNull($notification->sent_at);
    }

    public function test_already_sent_notifications_are_not_processed_again(): void
    {
        $admin = Admin::factory()->create();
        $sentAt = now()->subMinute()->startOfSecond();
        $notification = Notification::factory()->forAdmin($admin)->sent()->create([
            'sent_at' => $sentAt,
        ]);

        $calls = 0;
        $manager = new NotificationChannelManager;
        $manager->register('in_app', new class($calls) implements NotificationChannelInterface
        {
            public function __construct(private int &$calls) {}

            public function send(Notification $notification): void
            {
                $this->calls++;
            }
        });
        $this->app->instance(NotificationChannelManager::class, $manager);

        ProcessNotificationJob::dispatchSync($notification->id);
        ProcessNotificationJob::dispatchSync($notification->id);

        $this->assertSame(0, $calls);
        $this->assertSame(NotificationStatus::Sent, $notification->fresh()->status);
        $this->assertTrue($notification->fresh()->sent_at->equalTo($sentAt));
    }

    public function test_already_failed_notifications_are_not_processed_again(): void
    {
        $admin = Admin::factory()->create();
        $notification = Notification::factory()->forAdmin($admin)->create([
            'status' => NotificationStatus::Failed,
            'channel' => NotificationChannel::Email,
        ]);

        $calls = 0;
        $manager = new NotificationChannelManager;
        $manager->register('email', new class($calls) implements NotificationChannelInterface
        {
            public function __construct(private int &$calls) {}

            public function send(Notification $notification): void
            {
                $this->calls++;
            }
        });
        $this->app->instance(NotificationChannelManager::class, $manager);

        ProcessNotificationJob::dispatchSync($notification->id);

        $this->assertSame(0, $calls);
        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
        $this->assertNull($notification->fresh()->sent_at);
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
            'title' => $overrides['title'] ?? 'Hello {{customer_name}}',
            'message' => $overrides['message'] ?? 'Your reference is {{booking_number}} on {{date}}.',
            'subject' => $overrides['subject'] ?? null,
        ]);
    }
}
