<?php

namespace Tests\Feature\Notification;

use App\Actions\Notification\DispatchNotificationJobAction;
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
use Tests\TestCase;

class NotificationQueueDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_in_app_notifications_dispatch_to_in_app_queue(): void
    {
        Bus::fake([ProcessNotificationJob::class]);

        $admin = Admin::factory()->create();
        $this->createTemplate('queue_in_app', NotificationType::System, NotificationChannel::InApp);

        event(NotificationRequested::make(
            recipient: $admin,
            templateKey: 'queue_in_app',
            variables: ['customer_name' => 'Ops', 'booking_number' => 'BK-1', 'date' => '2026-07-16'],
        ));

        Bus::assertDispatched(
            ProcessNotificationJob::class,
            fn (ProcessNotificationJob $job): bool => $job->queue === NotificationChannelManager::QUEUE_IN_APP,
        );
    }

    public function test_email_notifications_dispatch_to_email_queue(): void
    {
        Bus::fake([ProcessNotificationJob::class]);

        $admin = Admin::factory()->create();
        $this->createTemplate('queue_email', NotificationType::Order, NotificationChannel::Email, [
            'title' => 'Order email',
            'message' => 'Your order was placed.',
            'subject' => 'Order update',
        ]);

        event(NotificationRequested::make(
            recipient: $admin,
            templateKey: 'queue_email',
        ));

        Bus::assertDispatched(
            ProcessNotificationJob::class,
            fn (ProcessNotificationJob $job): bool => $job->queue === NotificationChannelManager::QUEUE_EMAIL,
        );
    }

    public function test_sms_notifications_dispatch_to_sms_queue(): void
    {
        Bus::fake([ProcessNotificationJob::class]);

        $profile = CustomerProfile::factory()->create();
        NotificationPreference::factory()->forCustomerProfile($profile)->create([
            'notification_type' => NotificationType::Booking,
            'in_app' => true,
            'email' => true,
            'sms' => true,
        ]);
        $this->createTemplate('queue_sms', NotificationType::Booking, NotificationChannel::Sms, [
            'title' => 'Booking SMS',
            'message' => 'Your booking was updated.',
        ]);

        event(NotificationRequested::make(
            recipient: $profile,
            templateKey: 'queue_sms',
        ));

        Bus::assertDispatched(
            ProcessNotificationJob::class,
            fn (ProcessNotificationJob $job): bool => $job->queue === NotificationChannelManager::QUEUE_SMS,
        );
    }

    public function test_dispatch_action_selects_queue_from_notification_channel(): void
    {
        Bus::fake([ProcessNotificationJob::class]);

        $admin = Admin::factory()->create();
        $notification = Notification::factory()->forAdmin($admin)->create([
            'channel' => NotificationChannel::Email,
            'status' => NotificationStatus::Pending,
        ]);

        $this->app->make(DispatchNotificationJobAction::class)->handle($notification);

        Bus::assertDispatched(
            ProcessNotificationJob::class,
            fn (ProcessNotificationJob $job): bool => $job->notificationId === $notification->id
                && $job->queue === NotificationChannelManager::QUEUE_EMAIL,
        );
    }

    public function test_channel_manager_maps_each_channel_to_dedicated_queue(): void
    {
        $manager = $this->app->make(NotificationChannelManager::class);

        $this->assertSame(
            NotificationChannelManager::QUEUE_IN_APP,
            $manager->queueFor(NotificationChannel::InApp),
        );
        $this->assertSame(
            NotificationChannelManager::QUEUE_EMAIL,
            $manager->queueFor(NotificationChannel::Email),
        );
        $this->assertSame(
            NotificationChannelManager::QUEUE_SMS,
            $manager->queueFor(NotificationChannel::Sms),
        );
    }

    public function test_already_sent_notifications_remain_idempotent_when_job_runs(): void
    {
        $admin = Admin::factory()->create();
        $sentAt = now()->subMinute()->startOfSecond();
        $notification = Notification::factory()->forAdmin($admin)->sent()->create([
            'sent_at' => $sentAt,
            'channel' => NotificationChannel::InApp,
        ]);

        ProcessNotificationJob::dispatchSync($notification->id);

        $this->assertSame(NotificationStatus::Sent, $notification->fresh()->status);
        $this->assertTrue($notification->fresh()->sent_at->equalTo($sentAt));
    }

    public function test_already_failed_notifications_remain_idempotent_when_job_runs(): void
    {
        $admin = Admin::factory()->create();
        $notification = Notification::factory()->forAdmin($admin)->create([
            'status' => NotificationStatus::Failed,
            'channel' => NotificationChannel::Email,
        ]);

        ProcessNotificationJob::dispatchSync($notification->id);

        $this->assertSame(NotificationStatus::Failed, $notification->fresh()->status);
        $this->assertNull($notification->fresh()->sent_at);
    }

    public function test_in_app_channel_still_marks_notification_as_sent(): void
    {
        $admin = Admin::factory()->create();
        $this->createTemplate('queue_process_in_app', NotificationType::Inventory, NotificationChannel::InApp, [
            'title' => 'In-app notice',
            'message' => 'Delivered in app.',
        ]);

        event(NotificationRequested::make(
            recipient: $admin,
            templateKey: 'queue_process_in_app',
        ));

        $notification = Notification::query()->firstOrFail();

        $this->assertSame(NotificationChannel::InApp, $notification->channel);
        $this->assertSame(NotificationStatus::Delivered, $notification->status);
        $this->assertNotNull($notification->processing_started_at);
        $this->assertNotNull($notification->sent_at);
        $this->assertNotNull($notification->delivered_at);
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
