<?php

namespace Tests\Feature\Notification;

use App\Actions\Notification\MarkNotificationDeliveredAction;
use App\Actions\Notification\MarkNotificationFailedAction;
use App\Actions\Notification\MarkNotificationReadAction;
use App\Actions\Notification\MarkNotificationSentAction;
use App\Actions\Notification\StartNotificationProcessingAction;
use App\Contracts\Notification\NotificationChannelInterface;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Events\Notification\NotificationRequested;
use App\Jobs\Notification\ProcessNotificationJob;
use App\Models\Admin;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Services\Notification\NotificationChannelManager;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class NotificationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_lifecycle_pending_to_read(): void
    {
        $admin = Admin::factory()->create();
        $notification = Notification::factory()->forAdmin($admin)->create([
            'status' => NotificationStatus::Pending,
        ]);

        $notification = $this->app->make(StartNotificationProcessingAction::class)->handle($notification);
        $this->assertSame(NotificationStatus::Processing, $notification->status);
        $this->assertNotNull($notification->processing_started_at);

        $notification = $this->app->make(MarkNotificationSentAction::class)->handle($notification);
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertNotNull($notification->sent_at);

        $notification = $this->app->make(MarkNotificationDeliveredAction::class)->handle($notification);
        $this->assertSame(NotificationStatus::Delivered, $notification->status);
        $this->assertNotNull($notification->delivered_at);

        $notification = $this->app->make(MarkNotificationReadAction::class)->handle($admin, $notification->id);
        $this->assertSame(NotificationStatus::Read, $notification->status);
        $this->assertNotNull($notification->read_at);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $admin = Admin::factory()->create();
        $pending = Notification::factory()->forAdmin($admin)->create();
        $sent = Notification::factory()->forAdmin($admin)->sent()->create();
        $delivered = Notification::factory()->forAdmin($admin)->delivered()->create();

        $this->assertInvalidTransition(
            fn () => $this->app->make(MarkNotificationSentAction::class)->handle($pending),
        );
        $this->assertInvalidTransition(
            fn () => $this->app->make(MarkNotificationDeliveredAction::class)->handle($pending),
        );
        $this->assertInvalidTransition(
            fn () => $this->app->make(StartNotificationProcessingAction::class)->handle($sent),
        );
        $this->assertInvalidTransition(
            fn () => $this->app->make(MarkNotificationFailedAction::class)->handle($sent),
        );
        $this->assertInvalidTransition(
            fn () => $this->app->make(MarkNotificationReadAction::class)->handle($admin, $sent->id),
        );
        $this->assertInvalidTransition(
            fn () => $this->app->make(MarkNotificationSentAction::class)->handle($delivered),
        );
    }

    public function test_read_transition_is_idempotent_and_preserves_read_at(): void
    {
        $admin = Admin::factory()->create();
        $readAt = now()->subHour()->startOfSecond();
        $notification = Notification::factory()->forAdmin($admin)->read()->create([
            'read_at' => $readAt,
        ]);

        $action = $this->app->make(MarkNotificationReadAction::class);

        $first = $action->handle($admin, $notification->id);
        $second = $action->handle($admin, $notification->id);

        $this->assertSame(NotificationStatus::Read, $first->status);
        $this->assertSame(NotificationStatus::Read, $second->status);
        $this->assertTrue($second->read_at->equalTo($readAt));
    }

    public function test_failure_flow_marks_processing_as_failed_with_timestamp(): void
    {
        $admin = Admin::factory()->create();
        $notification = Notification::factory()->forAdmin($admin)->processing()->create();

        $failed = $this->app->make(MarkNotificationFailedAction::class)->handle($notification);

        $this->assertSame(NotificationStatus::Failed, $failed->status);
        $this->assertNotNull($failed->failed_at);
        $this->assertNotNull($failed->processing_started_at);
        $this->assertNull($failed->sent_at);
    }

    public function test_read_flow_requires_delivered_status(): void
    {
        $admin = Admin::factory()->create();
        $delivered = Notification::factory()->forAdmin($admin)->delivered()->create();

        $read = $this->app->make(MarkNotificationReadAction::class)->handle($admin, $delivered->id);

        $this->assertSame(NotificationStatus::Read, $read->status);
        $this->assertNotNull($read->read_at);
        $this->assertNotNull($read->delivered_at);
    }

    public function test_timestamps_are_never_overwritten(): void
    {
        $admin = Admin::factory()->create();
        $processingStartedAt = now()->subMinutes(5)->startOfSecond();
        $sentAt = now()->subMinutes(4)->startOfSecond();
        $deliveredAt = now()->subMinutes(3)->startOfSecond();

        $notification = Notification::factory()->forAdmin($admin)->create([
            'status' => NotificationStatus::Processing,
            'processing_started_at' => $processingStartedAt,
            'sent_at' => $sentAt,
        ]);

        $sent = $this->app->make(MarkNotificationSentAction::class)->handle($notification);
        $this->assertTrue($sent->processing_started_at->equalTo($processingStartedAt));
        $this->assertTrue($sent->sent_at->equalTo($sentAt));

        $sent->forceFill(['delivered_at' => $deliveredAt])->save();
        $delivered = $this->app->make(MarkNotificationDeliveredAction::class)->handle($sent->refresh());

        $this->assertTrue($delivered->processing_started_at->equalTo($processingStartedAt));
        $this->assertTrue($delivered->sent_at->equalTo($sentAt));
        $this->assertTrue($delivered->delivered_at->equalTo($deliveredAt));

        $read = $this->app->make(MarkNotificationReadAction::class)->handle($admin, $delivered->id);
        $originalReadAt = $read->read_at;

        $readAgain = $this->app->make(MarkNotificationReadAction::class)->handle($admin, $delivered->id);

        $this->assertTrue($readAgain->processing_started_at->equalTo($processingStartedAt));
        $this->assertTrue($readAgain->sent_at->equalTo($sentAt));
        $this->assertTrue($readAgain->delivered_at->equalTo($deliveredAt));
        $this->assertTrue($readAgain->read_at->equalTo($originalReadAt));
    }

    public function test_queue_processing_moves_pending_to_delivered_for_in_app(): void
    {
        $admin = Admin::factory()->create();
        $this->createTemplate('lifecycle_queue', NotificationType::System, NotificationChannel::InApp, [
            'title' => 'Lifecycle notice',
            'message' => 'Processed through the queue.',
        ]);

        event(NotificationRequested::make(
            recipient: $admin,
            templateKey: 'lifecycle_queue',
        ));

        $notification = Notification::query()->firstOrFail();

        $this->assertSame(NotificationStatus::Delivered, $notification->status);
        $this->assertNotNull($notification->processing_started_at);
        $this->assertNotNull($notification->sent_at);
        $this->assertNotNull($notification->delivered_at);
        $this->assertNull($notification->failed_at);
        $this->assertNull($notification->read_at);
    }

    public function test_queue_processing_failure_sets_failed_status_and_timestamp(): void
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

    public function test_non_pending_notifications_are_not_reprocessed(): void
    {
        $admin = Admin::factory()->create();

        foreach ([
            Notification::factory()->forAdmin($admin)->processing()->create(),
            Notification::factory()->forAdmin($admin)->sent()->create(),
            Notification::factory()->forAdmin($admin)->delivered()->create(),
            Notification::factory()->forAdmin($admin)->read()->create(),
            Notification::factory()->forAdmin($admin)->failed()->create(),
        ] as $notification) {
            $status = $notification->status;
            $processingStartedAt = $notification->processing_started_at;
            $sentAt = $notification->sent_at;
            $deliveredAt = $notification->delivered_at;
            $readAt = $notification->read_at;
            $failedAt = $notification->failed_at;

            ProcessNotificationJob::dispatchSync($notification->id);

            $fresh = $notification->fresh();

            $this->assertSame($status, $fresh->status);
            $this->assertTrue(
                ($processingStartedAt === null && $fresh->processing_started_at === null)
                || $fresh->processing_started_at?->equalTo($processingStartedAt),
            );
            $this->assertTrue(
                ($sentAt === null && $fresh->sent_at === null)
                || $fresh->sent_at?->equalTo($sentAt),
            );
            $this->assertTrue(
                ($deliveredAt === null && $fresh->delivered_at === null)
                || $fresh->delivered_at?->equalTo($deliveredAt),
            );
            $this->assertTrue(
                ($readAt === null && $fresh->read_at === null)
                || $fresh->read_at?->equalTo($readAt),
            );
            $this->assertTrue(
                ($failedAt === null && $fresh->failed_at === null)
                || $fresh->failed_at?->equalTo($failedAt),
            );
        }
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function assertInvalidTransition(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected INVALID_NOTIFICATION_STATUS_TRANSITION.');
        } catch (DomainException $exception) {
            $this->assertSame('INVALID_NOTIFICATION_STATUS_TRANSITION', $exception->getMessage());
        }
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
