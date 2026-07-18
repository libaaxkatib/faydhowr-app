<?php

namespace Tests\Feature\Uploads;

use App\Models\CustomerProfile;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeExpiredUploadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_removes_expired_unattached_uploads_only(): void
    {
        Storage::fake('local');

        $profile = CustomerProfile::factory()->for(User::factory())->create();

        Storage::disk('local')->put('uploads/expired.jpg', 'stale');
        $expired = Upload::factory()->for($profile)->expired()->create([
            'path' => 'uploads/expired.jpg',
        ]);

        Storage::disk('local')->put('uploads/fresh.jpg', 'fresh');
        $staged = Upload::factory()->for($profile)->create([
            'path' => 'uploads/fresh.jpg',
        ]);

        $attachedButOld = Upload::factory()->for($profile)->attached()->create([
            'expires_at' => now()->subDays(3),
        ]);

        $this
            ->artisan('uploads:purge-expired')
            ->expectsOutputToContain('Removed 1 expired upload(s).')
            ->assertSuccessful();

        $this->assertDatabaseMissing('uploads', ['id' => $expired->id]);
        Storage::disk('local')->assertMissing('uploads/expired.jpg');

        $this->assertDatabaseHas('uploads', ['id' => $staged->id]);
        Storage::disk('local')->assertExists('uploads/fresh.jpg');

        $this->assertDatabaseHas('uploads', ['id' => $attachedButOld->id]);
    }

    public function test_purge_is_a_no_op_when_nothing_is_expired(): void
    {
        $this
            ->artisan('uploads:purge-expired')
            ->expectsOutputToContain('Removed 0 expired upload(s).')
            ->assertSuccessful();
    }

    public function test_purge_command_is_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue(
            $events->contains(
                fn ($event): bool => str_contains((string) $event->command, 'uploads:purge-expired'),
            ),
        );
    }
}
