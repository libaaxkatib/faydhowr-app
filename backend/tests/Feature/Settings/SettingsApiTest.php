<?php

namespace Tests\Feature\Settings;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\Settings\SettingCategory;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\SystemSetting;
use App\Support\Settings\SettingsRegistry;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingsSeeder::class);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/admin/settings')->assertStatus(401);
    }

    public function test_admin_without_the_settings_permission_is_rejected(): void
    {
        $token = Admin::factory()->create(['role' => AdminRole::Sales])
            ->createToken('admin-panel')->plainTextToken;

        foreach ([
            ['getJson', '/api/v1/admin/settings'],
            ['getJson', '/api/v1/admin/settings/currency'],
            ['putJson', '/api/v1/admin/settings/tax'],
            ['getJson', '/api/v1/admin/settings/audit-logs'],
        ] as [$method, $endpoint]) {
            $this
                ->withToken($token)
                ->{$method}($endpoint)
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'FORBIDDEN');
        }
    }

    public function test_view_permission_is_not_enough_to_update_settings(): void
    {
        $this
            ->withToken($this->tokenWithPermission(AdminPermission::SettingsView))
            ->putJson('/api/v1/admin/settings/tax', ['tax.rate' => 5])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_settings_index_returns_all_categories_in_canonical_order(): void
    {
        $response = $this
            ->withToken($this->tokenWithPermission(AdminPermission::SettingsView))
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonPath('message', 'Settings retrieved successfully.');

        $categories = array_column($response->json('data'), 'category');
        $this->assertSame(SettingCategory::values(), $categories);
    }

    public function test_settings_index_masks_sensitive_values(): void
    {
        SystemSetting::query()
            ->category('smtp')->where('key', 'password')
            ->update(['value' => json_encode('super-secret')]);

        $response = $this
            ->withToken($this->tokenWithPermission(AdminPermission::SettingsView))
            ->getJson('/api/v1/admin/settings')
            ->assertOk();

        $this->assertStringNotContainsString('super-secret', $response->getContent());
    }

    public function test_category_show_returns_the_category_settings(): void
    {
        $response = $this
            ->withToken($this->tokenWithPermission(AdminPermission::SettingsView))
            ->getJson('/api/v1/admin/settings/currency')
            ->assertOk()
            ->assertJsonPath('data.category', 'currency');

        $settings = $response->json('data.settings');
        $this->assertSame('USD', $settings['currency.default']);
        $this->assertSame(2, $settings['currency.decimal_places']);
    }

    public function test_unknown_category_returns_a_404_error_code(): void
    {
        $token = $this->tokenWithPermission(AdminPermission::SettingsView);

        foreach (['store', 'branch'] as $category) {
            $this
                ->withToken($token)
                ->getJson('/api/v1/admin/settings/'.$category)
                ->assertStatus(404)
                ->assertJsonPath('error_code', 'SETTINGS_CATEGORY_NOT_FOUND');
        }
    }

    public function test_updating_a_category_persists_values_and_returns_them(): void
    {
        $response = $this
            ->withToken($this->tokenWithPermission(AdminPermission::SettingsManage))
            ->putJson('/api/v1/admin/settings/tax', [
                'tax.default' => true,
                'tax.rate' => 5,
                'tax.mode' => 'exclusive',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Tax settings updated successfully.');

        $settings = $response->json('data.settings');
        $this->assertSame(5, $settings['tax.rate']);
        $this->assertSame('exclusive', $settings['tax.mode']);

        $this->assertSame(
            5,
            SystemSetting::query()->category('tax')->where('key', 'rate')->sole()->value,
        );
    }

    public function test_unknown_keys_in_the_payload_are_rejected(): void
    {
        $token = $this->tokenWithPermission(AdminPermission::SettingsManage);

        $this
            ->withToken($token)
            ->putJson('/api/v1/admin/settings/tax', ['tax.unknown' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this
            ->withToken($token)
            ->putJson('/api/v1/admin/settings/tax', ['currency.symbol' => '$'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_per_key_type_validation_is_enforced(): void
    {
        $manage = $this->tokenWithPermission(AdminPermission::SettingsManage);

        $this->withToken($manage)
            ->putJson('/api/v1/admin/settings/tax', ['tax.rate' => 150])
            ->assertStatus(422);

        $this->withToken($manage)
            ->putJson('/api/v1/admin/settings/currency', ['currency.decimal_places' => 3])
            ->assertStatus(422);

        $this->withToken($manage)
            ->putJson('/api/v1/admin/settings/numbering', ['numbering.invoice_prefix' => 'lowercase!'])
            ->assertStatus(422);

        $this->withToken($manage)
            ->putJson('/api/v1/admin/settings/storage', ['storage.allowed_file_types' => ['exe']])
            ->assertStatus(422);

        $this->withToken($manage)
            ->putJson('/api/v1/admin/settings/backup', ['backup.last_run_at' => 'now'])
            ->assertStatus(422);
    }

    public function test_restore_defaults_resets_the_category(): void
    {
        $manage = $this->tokenWithPermission(AdminPermission::SettingsManage);

        $this->withToken($manage)
            ->putJson('/api/v1/admin/settings/currency', ['currency.default' => 'EUR'])
            ->assertOk();

        $response = $this->withToken($manage)
            ->postJson('/api/v1/admin/settings/currency/restore-defaults')
            ->assertOk();

        $this->assertSame('USD', $response->json('data.settings')['currency.default']);
    }

    public function test_settings_changes_are_exposed_through_the_audit_log_endpoint(): void
    {
        $token = $this->tokenWithPermission(AdminPermission::SettingsManage, AdminPermission::SettingsView);

        $this->withToken($token)
            ->putJson('/api/v1/admin/settings/tax', ['tax.rate' => 5])
            ->assertOk();
        $this->withToken($token)
            ->putJson('/api/v1/admin/settings/smtp', ['smtp.password' => 'secret'])
            ->assertOk();

        $response = $this
            ->withToken($token)
            ->getJson('/api/v1/admin/settings/audit-logs')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertStringNotContainsString('secret', $response->getContent());

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/settings/audit-logs?category=tax')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'tax.rate')
            ->assertJsonPath('data.0.new_value', 5);
    }

    public function test_company_logo_upload_stores_the_file_and_updates_the_setting(): void
    {
        Storage::fake('public');

        $this
            ->withToken($this->tokenWithPermission(AdminPermission::SettingsManage))
            ->post('/api/v1/admin/settings/company/logo', [
                'logo' => UploadedFile::fake()->image('logo.png', 512, 512),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', 'Logo uploaded successfully.');

        $logo = SystemSetting::query()->category('company')->where('key', 'logo')->sole();
        $this->assertNotNull($logo->value);

        $files = Storage::disk('public')->files('settings');
        $this->assertCount(1, $files);
    }

    public function test_company_logo_upload_rejects_invalid_files(): void
    {
        Storage::fake('public');

        $this
            ->withToken($this->tokenWithPermission(AdminPermission::SettingsManage))
            ->post('/api/v1/admin/settings/company/logo', [
                'logo' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_smtp_test_requires_a_valid_recipient(): void
    {
        $this
            ->withToken($this->tokenWithPermission(AdminPermission::SettingsManage))
            ->postJson('/api/v1/admin/settings/smtp/test', ['to_email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_smtp_test_fails_with_a_502_when_smtp_is_not_configured(): void
    {
        $this
            ->withToken($this->tokenWithPermission(AdminPermission::SettingsManage))
            ->postJson('/api/v1/admin/settings/smtp/test', ['to_email' => 'admin@example.com'])
            ->assertStatus(502)
            ->assertJsonPath('error_code', 'SMTP_TEST_FAILED');
    }

    public function test_sensitive_setting_keys_are_flagged_in_the_registry(): void
    {
        $this->assertTrue(SettingsRegistry::isSensitive(SettingCategory::Smtp, 'password'));
        $this->assertFalse(SettingsRegistry::isSensitive(SettingCategory::Smtp, 'host'));
    }

    private function tokenWithPermission(AdminPermission ...$permissions): string
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Manager]);

        foreach ($permissions as $permission) {
            DB::table('admin_permissions')->insert([
                'admin_id' => $admin->id,
                'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $admin->createToken('admin-panel')->plainTextToken;
    }
}
