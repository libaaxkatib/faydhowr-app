<?php

namespace Tests\Feature\Settings;

use App\Contracts\Settings\Services\SettingsServiceInterface;
use App\DataTransferObjects\Settings\CurrencySettingsData;
use App\DataTransferObjects\Settings\SmtpSettingsData;
use App\Enums\Settings\SettingCategory;
use App\Exceptions\Settings\SmtpTestFailedException;
use App\Models\Admin;
use App\Models\SettingsAuditLog;
use App\Models\SystemSetting;
use App\Support\Settings\SettingsRegistry;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingsSeeder::class);
    }

    public function test_all_settings_returns_every_category_in_canonical_order(): void
    {
        $all = $this->service()->allSettings();

        $this->assertSame(
            SettingCategory::values(),
            array_map(fn ($category) => $category->category->value, $all),
        );
    }

    public function test_category_settings_hydrates_a_typed_dto(): void
    {
        $data = $this->service()->categorySettings(SettingCategory::Currency);

        $this->assertInstanceOf(CurrencySettingsData::class, $data->values);
        $this->assertSame('USD', $data->values->default);
        $this->assertSame(2, $data->values->decimalPlaces);
        $this->assertNull($data->lastUpdatedByName);
    }

    public function test_update_category_persists_values_and_records_audit_entries(): void
    {
        $admin = Admin::factory()->create();

        $data = $this->service()->updateCategory(
            SettingCategory::Tax,
            ['tax.rate' => 5, 'tax.mode' => 'inclusive'],
            $admin,
            '10.0.0.9',
        );

        $this->assertSame(5, $data->values->rate);
        $this->assertSame('inclusive', $data->values->mode);
        $this->assertSame($admin->full_name, $data->lastUpdatedByName);

        $this->assertDatabaseHas('settings_audit_logs', [
            'category' => 'tax',
            'key' => 'rate',
            'changed_by' => $admin->id,
            'ip_address' => '10.0.0.9',
        ]);
        $this->assertDatabaseCount('settings_audit_logs', 2);
    }

    public function test_update_category_skips_unchanged_values(): void
    {
        $admin = Admin::factory()->create();

        $this->service()->updateCategory(
            SettingCategory::Tax,
            ['tax.mode' => 'exclusive'],
            $admin,
            null,
        );

        $this->assertDatabaseCount('settings_audit_logs', 0);
    }

    public function test_sensitive_values_are_masked_in_the_audit_trail(): void
    {
        $admin = Admin::factory()->create();

        $this->service()->updateCategory(
            SettingCategory::Smtp,
            ['smtp.password' => 'super-secret'],
            $admin,
            null,
        );

        $log = SettingsAuditLog::query()->sole();
        $this->assertSame(SettingsRegistry::mask(), $log->new_value);
        $this->assertNull($log->old_value);
    }

    public function test_sensitive_values_are_encrypted_at_rest_and_decrypted_for_internal_use(): void
    {
        $admin = Admin::factory()->create();

        $this->service()->updateCategory(
            SettingCategory::Smtp,
            ['smtp.password' => 'super-secret'],
            $admin,
            null,
        );

        $stored = SystemSetting::query()->category('smtp')->where('key', 'password')->sole();
        $this->assertNotSame('super-secret', $stored->value);
        $this->assertSame('super-secret', Crypt::decrypt($stored->value));

        $this->assertSame('super-secret', $this->service()->value('smtp.password'));
    }

    public function test_resubmitting_the_same_sensitive_value_is_not_treated_as_a_change(): void
    {
        $admin = Admin::factory()->create();

        $this->service()->updateCategory(SettingCategory::Smtp, ['smtp.password' => 'secret'], $admin, null);
        $this->service()->updateCategory(SettingCategory::Smtp, ['smtp.password' => 'secret'], $admin, null);

        $this->assertDatabaseCount('settings_audit_logs', 1);
    }

    public function test_smtp_dto_never_exposes_the_stored_password(): void
    {
        $admin = Admin::factory()->create();
        $this->service()->updateCategory(SettingCategory::Smtp, ['smtp.password' => 'secret'], $admin, null);

        $data = $this->service()->categorySettings(SettingCategory::Smtp);

        $this->assertInstanceOf(SmtpSettingsData::class, $data->values);
        $this->assertTrue($data->values->hasPassword);
        $this->assertSame(SettingsRegistry::mask(), $data->values->toArray()['smtp.password']);
        $this->assertStringNotContainsString('secret', json_encode($data->values->toArray()));
    }

    public function test_restore_defaults_resets_values_and_audits_the_changes(): void
    {
        $admin = Admin::factory()->create();
        $this->service()->updateCategory(SettingCategory::Currency, ['currency.default' => 'EUR'], $admin, null);

        $data = $this->service()->restoreDefaults(SettingCategory::Currency, $admin, null);

        $this->assertSame('USD', $data->values->default);
        $this->assertDatabaseCount('settings_audit_logs', 2);
    }

    public function test_value_returns_cached_settings_and_updates_invalidate_the_cache(): void
    {
        $admin = Admin::factory()->create();
        $service = $this->service();

        $this->assertSame('USD', $service->value('currency.default'));

        $service->updateCategory(SettingCategory::Currency, ['currency.default' => 'EUR'], $admin, null);

        $this->assertSame('EUR', $service->value('currency.default'));
    }

    public function test_smtp_test_fails_when_no_host_is_configured(): void
    {
        $this->expectException(SmtpTestFailedException::class);

        $this->service()->sendTestEmail('admin@example.com');
    }

    private function service(): SettingsServiceInterface
    {
        return $this->app->make(SettingsServiceInterface::class);
    }
}
