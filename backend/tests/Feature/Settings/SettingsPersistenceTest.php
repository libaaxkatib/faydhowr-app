<?php

namespace Tests\Feature\Settings;

use App\Contracts\Settings\Repositories\SystemSettingRepositoryInterface;
use App\Contracts\Settings\Services\SettingsServiceInterface;
use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\Settings\SettingCategory;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\SystemSetting;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression coverage for the Phase 5 fix: production had zero system_settings
 * rows (SystemSettingsSeeder was never run there), and
 * SettingsService::updateCategory() silently skipped any key with no
 * pre-existing row instead of creating one, so Settings Save was a
 * false-success no-op. Deliberately does NOT seed SystemSettingsSeeder in
 * setUp() — every test here starts from a genuinely empty system_settings
 * table, the exact production condition that exposed the bug.
 */
class SettingsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_category_with_no_existing_rows_creates_them_and_persists_the_value(): void
    {
        $this->assertDatabaseCount('system_settings', 0);

        $admin = Admin::factory()->create();

        $data = $this->service()->updateCategory(
            SettingCategory::Tax,
            ['tax.rate' => 7, 'tax.mode' => 'inclusive'],
            $admin,
            null,
        );

        $this->assertSame(7, $data->values->rate);
        $this->assertSame('inclusive', $data->values->mode);

        $this->assertDatabaseHas('system_settings', [
            'category' => 'tax',
            'key' => 'rate',
            'value' => json_encode(7),
        ]);
        $this->assertDatabaseHas('system_settings', [
            'category' => 'tax',
            'key' => 'mode',
            'value' => json_encode('inclusive'),
        ]);

        // A subsequent read (a fresh call, as a new request would make) sees the same value.
        $reread = $this->service()->categorySettings(SettingCategory::Tax);
        $this->assertSame(7, $reread->values->rate);
        $this->assertSame('inclusive', $reread->values->mode);
    }

    public function test_missing_rows_are_created_with_their_registry_default_and_is_sensitive_flag(): void
    {
        $admin = Admin::factory()->create();

        $this->service()->updateCategory(SettingCategory::Currency, ['currency.symbol' => '€'], $admin, null);

        // The key that was actually saved:
        $symbol = SystemSetting::query()->category('currency')->where('key', 'symbol')->sole();
        $this->assertSame('€', $symbol->value);
        $this->assertSame('$', $symbol->default_value);
        $this->assertFalse($symbol->is_sensitive);

        // Other Currency keys are untouched by this save and were never created —
        // upsert-on-save only materializes the keys actually being written.
        $this->assertDatabaseMissing('system_settings', ['category' => 'currency', 'key' => 'default']);
    }

    public function test_an_untouched_key_in_the_same_request_is_not_created(): void
    {
        $admin = Admin::factory()->create();

        $this->service()->updateCategory(SettingCategory::Tax, ['tax.rate' => 3], $admin, null);

        $this->assertDatabaseHas('system_settings', ['category' => 'tax', 'key' => 'rate']);
        $this->assertDatabaseMissing('system_settings', ['category' => 'tax', 'key' => 'mode']);
        $this->assertDatabaseMissing('system_settings', ['category' => 'tax', 'key' => 'default']);
    }

    public function test_creating_a_missing_row_is_audited_as_a_change_from_its_default(): void
    {
        $admin = Admin::factory()->create();

        $this->service()->updateCategory(SettingCategory::Numbering, ['numbering.invoice_prefix' => 'INV2'], $admin, '10.0.0.5');

        $this->assertDatabaseHas('settings_audit_logs', [
            'category' => 'numbering',
            'key' => 'invoice_prefix',
            'old_value' => json_encode('INV'), // registry default
            'new_value' => json_encode('INV2'),
            'ip_address' => '10.0.0.5',
        ]);
    }

    public function test_saving_the_registry_default_value_for_a_missing_row_still_creates_it_without_an_audit_entry(): void
    {
        $admin = Admin::factory()->create();

        // "USD" is the registry default for currency.default — writing the
        // same value should still materialize the row (so future reads and
        // saves see it), but is not itself a "change" worth auditing.
        $this->service()->updateCategory(SettingCategory::Currency, ['currency.default' => 'USD'], $admin, null);

        $this->assertDatabaseHas('system_settings', ['category' => 'currency', 'key' => 'default', 'value' => json_encode('USD')]);
        $this->assertDatabaseCount('settings_audit_logs', 0);
    }

    public function test_sensitive_values_are_still_encrypted_and_masked_when_the_row_did_not_exist_yet(): void
    {
        $admin = Admin::factory()->create();

        $this->service()->updateCategory(SettingCategory::Smtp, ['smtp.password' => 'first-secret'], $admin, null);

        $stored = SystemSetting::query()->category('smtp')->where('key', 'password')->sole();
        $this->assertTrue($stored->is_sensitive);
        $this->assertNotSame('first-secret', $stored->value);
        $this->assertSame('first-secret', Crypt::decrypt($stored->value));

        $log = DB::table('settings_audit_logs')->where('category', 'smtp')->where('key', 'password')->sole();
        $this->assertSame(json_encode(SettingsRegistry::mask()), $log->new_value);
        $this->assertNull($log->old_value);

        $data = $this->service()->categorySettings(SettingCategory::Smtp);
        $this->assertStringNotContainsString('first-secret', json_encode($data->values->toArray()));
    }

    public function test_invalid_values_are_still_rejected_by_the_api_when_no_rows_exist_yet(): void
    {
        $this->assertDatabaseCount('system_settings', 0);

        $this->withToken($this->manageToken())
            ->putJson('/api/v1/admin/settings/tax', ['tax.rate' => 500])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        // Validation runs before persistence — an invalid payload must not create a row either.
        $this->assertDatabaseCount('system_settings', 0);
    }

    public function test_unregistered_keys_are_still_rejected_when_no_rows_exist_yet(): void
    {
        $this->withToken($this->manageToken())
            ->putJson('/api/v1/admin/settings/tax', ['tax.unknown' => 1])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('system_settings', 0);
    }

    public function test_unauthorized_admins_still_cannot_write_settings_when_no_rows_exist_yet(): void
    {
        $token = Admin::factory()->create(['role' => AdminRole::Sales])
            ->createToken('admin-panel')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/admin/settings/tax', ['tax.rate' => 5])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->assertDatabaseCount('system_settings', 0);
    }

    public function test_view_only_permission_still_cannot_write_settings_when_no_rows_exist_yet(): void
    {
        $this->withToken($this->tokenWithPermission(AdminPermission::SettingsView))
            ->putJson('/api/v1/admin/settings/tax', ['tax.rate' => 5])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->assertDatabaseCount('system_settings', 0);
    }

    /**
     * End-to-end reproduction of the exact production defect report: GET
     * before any save is empty, PUT saves a value with a 200 + real
     * persisted data (not a false-success no-op), and a subsequent GET
     * (a fresh request) returns the saved value.
     *
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    public static function representativeCategoryProvider(): array
    {
        return [
            'company' => ['company', 'company.name', 'Fayadhowr Test Co'],
            'currency' => ['currency', 'currency.symbol', '€'],
            'numbering' => ['numbering', 'numbering.invoice_prefix', 'INV9'],
            'notifications' => ['notifications', 'notifications.email', false],
            'localization' => ['localization', 'localization.language', 'so'],
        ];
    }

    #[DataProvider('representativeCategoryProvider')]
    public function test_save_then_get_round_trips_across_representative_categories_with_no_existing_rows(
        string $category,
        string $qualifiedKey,
        mixed $value,
    ): void {
        $token = $this->manageToken();

        $before = $this->withToken($token)
            ->getJson("/api/v1/admin/settings/{$category}")
            ->assertOk()
            ->json('data.settings');
        $this->assertNull($before[$qualifiedKey]);

        $putResponse = $this->withToken($token)
            ->putJson("/api/v1/admin/settings/{$category}", [$qualifiedKey => $value])
            ->assertOk();
        $this->assertSame($value, $putResponse->json('data.settings')[$qualifiedKey]);

        // Simulate a genuinely separate subsequent request.
        $getResponse = $this->withToken($token)
            ->getJson("/api/v1/admin/settings/{$category}")
            ->assertOk();
        $this->assertSame($value, $getResponse->json('data.settings')[$qualifiedKey]);
    }

    public function test_concurrent_creation_of_the_same_missing_row_does_not_error(): void
    {
        $admin = Admin::factory()->create();
        $repository = $this->app->make(SystemSettingRepositoryInterface::class);

        // Simulate the race: another request already created the row between
        // this request reading byCategory() and attempting to create it.
        SystemSetting::query()->create([
            'category' => 'tax',
            'key' => 'rate',
            'value' => 0,
            'default_value' => 0,
            'is_sensitive' => false,
        ]);

        $setting = $repository->findOrCreate(SettingCategory::Tax, 'rate', false, 0);

        $this->assertDatabaseCount('system_settings', 1);
        $this->assertSame($setting->id, SystemSetting::query()->category('tax')->where('key', 'rate')->sole()->id);

        // Save still proceeds normally against the now-existing row.
        $this->service()->updateCategory(SettingCategory::Tax, ['tax.rate' => 9], $admin, null);
        $this->assertSame(9, SystemSetting::query()->category('tax')->where('key', 'rate')->sole()->value);
    }

    private function service(): SettingsServiceInterface
    {
        return $this->app->make(SettingsServiceInterface::class);
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

    private function manageToken(): string
    {
        return $this->tokenWithPermission(AdminPermission::SettingsManage, AdminPermission::SettingsView);
    }
}
