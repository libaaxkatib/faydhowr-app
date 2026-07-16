<?php

namespace Tests\Feature\Postgres;

use App\Models\Admin;
use App\Models\CustomerProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationPostgresIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This integration test requires PostgreSQL.');
        }
    }

    public function test_notifications_reject_an_unapproved_type(): void
    {
        $this->expectException(QueryException::class);

        DB::table('notifications')->insert([
            ...$this->notificationAttributes(),
            'type' => 'invalid',
        ]);
    }

    public function test_notifications_reject_an_unapproved_channel(): void
    {
        $this->expectException(QueryException::class);

        DB::table('notifications')->insert([
            ...$this->notificationAttributes(),
            'channel' => 'push',
        ]);
    }

    public function test_notifications_reject_an_unapproved_status(): void
    {
        $this->expectException(QueryException::class);

        DB::table('notifications')->insert([
            ...$this->notificationAttributes(),
            'status' => 'queued',
        ]);
    }

    public function test_notifications_reject_an_unapproved_recipient_type(): void
    {
        $this->expectException(QueryException::class);

        DB::table('notifications')->insert([
            ...$this->notificationAttributes(),
            'recipient_type' => 'App\\Models\\User',
            'recipient_id' => 1,
        ]);
    }

    public function test_notifications_reject_read_status_without_read_at(): void
    {
        $this->expectException(QueryException::class);

        DB::table('notifications')->insert([
            ...$this->notificationAttributes(),
            'status' => 'read',
            'read_at' => null,
        ]);
    }

    public function test_notifications_accept_admin_and_customer_profile_recipients(): void
    {
        $admin = Admin::factory()->create();
        $profile = CustomerProfile::factory()->create();

        $adminNotificationId = DB::table('notifications')->insertGetId([
            ...$this->notificationAttributes($admin->id, Admin::class),
            'type' => 'inventory',
        ]);

        $customerNotificationId = DB::table('notifications')->insertGetId([
            ...$this->notificationAttributes($profile->id, CustomerProfile::class),
            'type' => 'booking',
            'channel' => 'sms',
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $adminNotificationId,
            'recipient_type' => Admin::class,
            'recipient_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $customerNotificationId,
            'recipient_type' => CustomerProfile::class,
            'recipient_id' => $profile->id,
        ]);
    }

    public function test_notifications_accept_read_status_with_read_at(): void
    {
        $id = DB::table('notifications')->insertGetId([
            ...$this->notificationAttributes(),
            'status' => 'read',
            'read_at' => now(),
            'sent_at' => now()->subMinute(),
        ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $id,
            'status' => 'read',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationAttributes(
        ?int $recipientId = null,
        string $recipientType = Admin::class,
    ): array {
        $recipientId ??= Admin::factory()->create()->id;

        return [
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'type' => 'system',
            'channel' => 'in_app',
            'status' => 'pending',
            'title' => 'Test notification',
            'message' => 'Foundation integrity check.',
            'data' => null,
            'event_id' => 'evt-'.$recipientId.'-'.uniqid(),
            'read_at' => null,
            'sent_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
