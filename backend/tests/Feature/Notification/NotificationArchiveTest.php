<?php

namespace Tests\Feature\Notification;

use App\Actions\Notification\ArchiveNotificationAction;
use App\Actions\Notification\ArchiveOldNotificationsAction;
use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\NotificationArchiveStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationType;
use App\Models\Admin;
use App\Models\ArchivedNotification;
use App\Models\CustomerProfile;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class NotificationArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_read_notification_preserves_payload_and_deletes_original(): void
    {
        $admin = Admin::factory()->create();
        $createdAt = now()->subDays(3)->startOfSecond();
        $notification = Notification::factory()->forAdmin($admin)->read()->create([
            'type' => NotificationType::Booking,
            'channel' => NotificationChannel::InApp,
            'title' => 'Booking update',
            'message' => 'Your booking was confirmed.',
            'data' => ['booking_id' => 42],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $originalId = $notification->id;

        $archived = $this->app->make(ArchiveNotificationAction::class)->handle($notification);

        $this->assertDatabaseMissing('notifications', ['id' => $originalId]);
        $this->assertDatabaseHas('archived_notifications', [
            'id' => $archived->id,
            'original_notification_id' => $originalId,
            'recipient_type' => Admin::class,
            'recipient_id' => $admin->id,
            'type' => NotificationType::Booking->value,
            'channel' => NotificationChannel::InApp->value,
            'status' => NotificationArchiveStatus::Read->value,
            'title' => 'Booking update',
            'message' => 'Your booking was confirmed.',
        ]);

        $this->assertSame(['booking_id' => 42], $archived->data);
        $this->assertNotNull($archived->archived_at);
        $this->assertTrue($archived->created_at->equalTo($createdAt));
        $this->assertNotNull($archived->processing_started_at);
        $this->assertNotNull($archived->sent_at);
        $this->assertNotNull($archived->delivered_at);
        $this->assertNotNull($archived->read_at);
    }

    public function test_archive_failed_notification(): void
    {
        $profile = CustomerProfile::factory()->create();
        $notification = Notification::factory()->forCustomerProfile($profile)->failed()->create([
            'type' => NotificationType::Payment,
            'channel' => NotificationChannel::Email,
        ]);

        $archived = $this->app->make(ArchiveNotificationAction::class)->handle($notification);

        $this->assertSame(NotificationArchiveStatus::Failed, $archived->status);
        $this->assertNotNull($archived->failed_at);
        $this->assertNull($archived->read_at);
        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    public function test_non_terminal_notifications_cannot_be_archived(): void
    {
        $admin = Admin::factory()->create();

        foreach ([
            Notification::factory()->forAdmin($admin)->create(['status' => NotificationStatus::Pending]),
            Notification::factory()->forAdmin($admin)->processing()->create(),
            Notification::factory()->forAdmin($admin)->sent()->create(),
            Notification::factory()->forAdmin($admin)->delivered()->create(),
        ] as $notification) {
            try {
                $this->app->make(ArchiveNotificationAction::class)->handle($notification);
                $this->fail('Expected NOTIFICATION_NOT_ARCHIVABLE.');
            } catch (DomainException $exception) {
                $this->assertSame('NOTIFICATION_NOT_ARCHIVABLE', $exception->getMessage());
            }

            $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
            $this->assertDatabaseMissing('archived_notifications', [
                'original_notification_id' => $notification->id,
            ]);
        }
    }

    public function test_archive_transaction_rolls_back_when_copy_fails(): void
    {
        $admin = Admin::factory()->create();
        $notification = Notification::factory()->forAdmin($admin)->read()->create();

        ArchivedNotification::creating(function (): void {
            throw new RuntimeException('Forced archive failure.');
        });

        try {
            $this->app->make(ArchiveNotificationAction::class)->handle($notification);
            $this->fail('Expected archive failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Forced archive failure.', $exception->getMessage());
        }

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
        $this->assertSame(0, ArchivedNotification::query()->count());
    }

    public function test_archive_old_notifications_archives_only_terminal_rows(): void
    {
        $admin = Admin::factory()->create();

        $oldRead = Notification::factory()->forAdmin($admin)->read()->create([
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);
        $oldFailed = Notification::factory()->forAdmin($admin)->failed()->create([
            'created_at' => now()->subDays(8),
            'updated_at' => now()->subDays(8),
        ]);
        $recentRead = Notification::factory()->forAdmin($admin)->read()->create([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $pending = Notification::factory()->forAdmin($admin)->create([
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ]);

        $count = $this->app->make(ArchiveOldNotificationsAction::class)->handle(now()->subDays(5));

        $this->assertSame(2, $count);
        $this->assertDatabaseMissing('notifications', ['id' => $oldRead->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $oldFailed->id]);
        $this->assertDatabaseHas('notifications', ['id' => $recentRead->id]);
        $this->assertDatabaseHas('notifications', ['id' => $pending->id]);
        $this->assertSame(2, ArchivedNotification::query()->count());
    }

    public function test_admin_can_list_archived_notifications_newest_first_with_filters(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $profile = CustomerProfile::factory()->create();

        ArchivedNotification::factory()->forAdmin($admin)->create([
            'type' => NotificationType::Booking,
            'channel' => NotificationChannel::InApp,
            'status' => NotificationArchiveStatus::Read,
            'archived_at' => now()->subDay(),
            'title' => 'Older archive',
        ]);
        $newer = ArchivedNotification::factory()->forCustomerProfile($profile)->failed()->create([
            'type' => NotificationType::Payment,
            'channel' => NotificationChannel::Email,
            'archived_at' => now(),
            'title' => 'Newer archive',
        ]);
        ArchivedNotification::factory()->forAdmin($admin)->create([
            'type' => NotificationType::System,
            'channel' => NotificationChannel::Sms,
            'status' => NotificationArchiveStatus::Read,
            'archived_at' => now()->subHours(2),
        ]);

        $token = $admin->createToken('admin-panel')->plainTextToken;

        $list = $this
            ->withToken($token)
            ->getJson('/api/v1/admin/archived-notifications')
            ->assertOk()
            ->assertJsonPath('message', 'Archived notifications retrieved successfully.')
            ->assertJsonPath('data.pagination.total', 3)
            ->json('data.items');

        $this->assertSame($newer->id, $list[0]['id']);
        $this->assertTrue($list[0]['archived_at'] >= $list[1]['archived_at']);
        $this->assertTrue($list[1]['archived_at'] >= $list[2]['archived_at']);

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/archived-notifications?'.http_build_query([
                'recipient_type' => CustomerProfile::class,
                'type' => NotificationType::Payment->value,
                'channel' => NotificationChannel::Email->value,
                'status' => NotificationArchiveStatus::Failed->value,
            ]))
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $newer->id)
            ->assertJsonPath('data.items.0.original_notification_id', $newer->original_notification_id);
    }

    public function test_notifications_manage_permission_is_required_for_archive_listing(): void
    {
        $admin = Admin::factory()->create([
            'role' => AdminRole::Manager,
        ]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/archived-notifications')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->grantPermissions($admin, [AdminPermission::NotificationsManage]);
        $this->app['auth']->forgetGuards();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/archived-notifications')
            ->assertOk();
    }

    public function test_customers_cannot_access_archived_notifications(): void
    {
        $user = User::factory()->create();
        CustomerProfile::factory()->create(['user_id' => $user->id]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/admin/archived-notifications')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this
            ->getJson('/api/v1/admin/archived-notifications')
            ->assertUnauthorized();
    }

    /**
     * @param  list<AdminPermission>  $permissions
     */
    private function grantPermissions(Admin $admin, array $permissions): void
    {
        $now = now();

        DB::table('admin_permissions')->insert(
            collect($permissions)
                ->map(fn (AdminPermission $permission): array => [
                    'admin_id' => $admin->id,
                    'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
        );
    }
}
