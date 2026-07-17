<?php

namespace Tests\Feature\Settings;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\SystemSetting;
use Database\Seeders\BranchSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(BranchSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/admin/backups')->assertStatus(401);
    }

    public function test_creating_a_backup_stores_a_snapshot_and_stamps_the_last_run(): void
    {
        $response = $this
            ->withToken($this->manageToken())
            ->postJson('/api/v1/admin/backups')
            ->assertStatus(201)
            ->assertJsonPath('message', 'Backup created successfully.');

        $id = $response->json('data.id');
        $this->assertNotNull($id);
        Storage::disk('local')->assertExists('settings-backups/'.$id.'.json');

        $lastRun = SystemSetting::query()->category('backup')->where('key', 'last_run_at')->sole();
        $this->assertNotNull($lastRun->value);

        $this->assertDatabaseHas('settings_audit_logs', [
            'category' => 'backup',
            'key' => 'run',
        ]);
    }

    public function test_backups_index_lists_stored_backups_newest_first(): void
    {
        $token = $this->superAdminToken();

        $first = $this->withToken($token)->postJson('/api/v1/admin/backups')->json('data.id');
        $this->travel(1)->minutes();
        $second = $this->withToken($token)->postJson('/api/v1/admin/backups')->json('data.id');

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/backups')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.1.id', $first);
    }

    public function test_a_backup_archive_can_be_downloaded(): void
    {
        $token = $this->superAdminToken();
        $id = $this->withToken($token)->postJson('/api/v1/admin/backups')->json('data.id');

        $this
            ->withToken($token)
            ->get("/api/v1/admin/backups/{$id}/download")
            ->assertOk()
            ->assertDownload($id.'.json');
    }

    public function test_downloading_an_unknown_backup_returns_404(): void
    {
        $this
            ->withToken($this->manageToken())
            ->getJson('/api/v1/admin/backups/backup-unknown/download')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'BACKUP_NOT_FOUND');
    }

    public function test_restore_requires_the_confirmation_phrase(): void
    {
        $token = $this->superAdminToken();
        $id = $this->withToken($token)->postJson('/api/v1/admin/backups')->json('data.id');

        $this
            ->withToken($token)
            ->postJson("/api/v1/admin/backups/{$id}/restore")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BACKUP_RESTORE_NOT_CONFIRMED');

        $this
            ->withToken($token)
            ->postJson("/api/v1/admin/backups/{$id}/restore", ['confirmation' => 'restore'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'BACKUP_RESTORE_NOT_CONFIRMED');
    }

    public function test_only_a_super_admin_can_restore_a_backup(): void
    {
        $manage = $this->manageToken();
        $id = $this->withToken($manage)->postJson('/api/v1/admin/backups')->json('data.id');

        $this
            ->withToken($manage)
            ->postJson("/api/v1/admin/backups/{$id}/restore", ['confirmation' => 'RESTORE'])
            ->assertStatus(403);
    }

    public function test_restoring_a_backup_reverts_changed_settings(): void
    {
        $token = $this->superAdminToken();
        $id = $this->withToken($token)->postJson('/api/v1/admin/backups')->json('data.id');

        $this->withToken($token)
            ->putJson('/api/v1/admin/settings/currency', ['currency.default' => 'EUR'])
            ->assertOk();

        $this->withToken($token)
            ->postJson("/api/v1/admin/backups/{$id}/restore", ['confirmation' => 'RESTORE'])
            ->assertOk()
            ->assertJsonPath('message', 'Backup restored successfully.');

        $this->assertSame(
            'USD',
            SystemSetting::query()->category('currency')->where('key', 'default')->sole()->value,
        );
        $this->assertDatabaseHas('settings_audit_logs', [
            'category' => 'backup',
            'key' => 'restore',
        ]);
    }

    public function test_restoring_a_backup_preserves_encrypted_sensitive_values(): void
    {
        $token = $this->superAdminToken();

        $this->withToken($token)
            ->putJson('/api/v1/admin/settings/smtp', ['smtp.password' => 'original-secret'])
            ->assertOk();

        $id = $this->withToken($token)->postJson('/api/v1/admin/backups')->json('data.id');

        $this->withToken($token)
            ->putJson('/api/v1/admin/settings/smtp', ['smtp.password' => 'changed-secret'])
            ->assertOk();

        $this->withToken($token)
            ->postJson("/api/v1/admin/backups/{$id}/restore", ['confirmation' => 'RESTORE'])
            ->assertOk();

        $stored = SystemSetting::query()->category('smtp')->where('key', 'password')->sole();
        $this->assertNotSame('original-secret', $stored->value);
        $this->assertSame('original-secret', Crypt::decrypt($stored->value));

        $snapshot = Storage::disk('local')->get('settings-backups/'.$id.'.json');
        $this->assertStringNotContainsString('original-secret', $snapshot);
    }

    public function test_restoring_a_corrupt_backup_fails_without_changing_data(): void
    {
        $token = $this->superAdminToken();

        Storage::disk('local')->put('settings-backups/backup-corrupt.json', 'not-json');

        $this->withToken($token)
            ->putJson('/api/v1/admin/settings/currency', ['currency.default' => 'EUR'])
            ->assertOk();

        $this->withToken($token)
            ->postJson('/api/v1/admin/backups/backup-corrupt/restore', ['confirmation' => 'RESTORE'])
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'BACKUP_NOT_FOUND');

        $this->assertSame(
            'EUR',
            SystemSetting::query()->category('currency')->where('key', 'default')->sole()->value,
        );
    }

    private function superAdminToken(): string
    {
        return Admin::factory()->superAdmin()->create()
            ->createToken('admin-panel')->plainTextToken;
    }

    private function manageToken(): string
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Manager]);

        DB::table('admin_permissions')->insert([
            'admin_id' => $admin->id,
            'permission_id' => Permission::query()->where('key', AdminPermission::SettingsManage->value)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $admin->createToken('admin-panel')->plainTextToken;
    }
}
