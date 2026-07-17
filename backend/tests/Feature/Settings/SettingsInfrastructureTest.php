<?php

namespace Tests\Feature\Settings;

use App\Contracts\Settings\Repositories\BranchRepositoryInterface;
use App\Contracts\Settings\Repositories\SettingsAuditRepositoryInterface;
use App\Contracts\Settings\Repositories\SystemSettingRepositoryInterface;
use App\Contracts\Settings\Services\AuditServiceInterface;
use App\Contracts\Settings\Services\BackupServiceInterface;
use App\Contracts\Settings\Services\BranchServiceInterface;
use App\Contracts\Settings\Services\SettingsServiceInterface;
use App\Enums\Settings\BranchStatus;
use App\Enums\Settings\SettingCategory;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\SettingsAuditLog;
use App\Models\SystemSetting;
use App\Repositories\Settings\BranchRepository;
use App\Repositories\Settings\SettingsAuditRepository;
use App\Repositories\Settings\SystemSettingRepository;
use App\Services\Settings\AuditService;
use App\Services\Settings\BackupService;
use App\Services\Settings\BranchService;
use App\Services\Settings\SettingsService;
use App\Support\Settings\SettingsRegistry;
use Database\Seeders\BranchSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SettingsInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_tables_are_migrated(): void
    {
        $this->assertTrue(Schema::hasTable('system_settings'));
        $this->assertTrue(Schema::hasTable('branches'));
        $this->assertTrue(Schema::hasTable('settings_audit_logs'));
    }

    public function test_contracts_are_bound_to_their_implementations(): void
    {
        $bindings = [
            SystemSettingRepositoryInterface::class => SystemSettingRepository::class,
            BranchRepositoryInterface::class => BranchRepository::class,
            SettingsAuditRepositoryInterface::class => SettingsAuditRepository::class,
            AuditServiceInterface::class => AuditService::class,
            SettingsServiceInterface::class => SettingsService::class,
            BranchServiceInterface::class => BranchService::class,
            BackupServiceInterface::class => BackupService::class,
        ];

        foreach ($bindings as $contract => $implementation) {
            $this->assertInstanceOf($implementation, $this->app->make($contract));
        }
    }

    public function test_system_setting_model_casts_json_values_and_scopes_by_category(): void
    {
        SystemSetting::factory()->create([
            'category' => 'storage',
            'key' => 'allowed_file_types',
            'value' => ['jpg', 'pdf'],
            'default_value' => ['jpg'],
        ]);
        SystemSetting::factory()->create(['category' => 'company', 'key' => 'name', 'value' => 'Acme']);

        $setting = SystemSetting::query()->category('storage')->sole();

        $this->assertSame(['jpg', 'pdf'], $setting->value);
        $this->assertSame(['jpg'], $setting->default_value);
        $this->assertFalse($setting->is_sensitive);
        $this->assertSame('storage.allowed_file_types', $setting->qualifiedKey());
    }

    public function test_branch_model_casts_status_and_default_flag(): void
    {
        $branch = Branch::factory()->comingSoon()->create();

        $this->assertSame(BranchStatus::ComingSoon, $branch->status);
        $this->assertFalse($branch->is_default);
        $this->assertFalse($branch->isActive());
        $this->assertCount(0, Branch::query()->active()->get());
    }

    public function test_system_setting_repository_reads_and_writes_values(): void
    {
        $admin = Admin::factory()->create();
        SystemSetting::factory()->create(['category' => 'tax', 'key' => 'rate', 'value' => 0]);
        SystemSetting::factory()->create(['category' => 'tax', 'key' => 'mode', 'value' => 'exclusive']);

        $repository = $this->app->make(SystemSettingRepositoryInterface::class);

        $byCategory = $repository->byCategory(SettingCategory::Tax);
        $this->assertSame(['mode', 'rate'], $byCategory->keys()->all());

        $setting = $repository->find(SettingCategory::Tax, 'rate');
        $this->assertNotNull($setting);

        $repository->setValue($setting, 5, $admin->id);

        $this->assertSame(5, $setting->refresh()->value);
        $this->assertSame($admin->id, $setting->updated_by);
    }

    public function test_branch_repository_manages_default_and_activation(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $default = Branch::factory()->default()->create();
        $other = Branch::factory()->comingSoon()->create();

        $repository = $this->app->make(BranchRepositoryInterface::class);

        $this->assertSame($default->id, $repository->findDefault()?->id);
        $this->assertSame($other->id, $repository->findByCode($other->code)?->id);

        $repository->markActive($other, $admin->id);
        $this->assertSame(BranchStatus::Active, $other->refresh()->status);
        $this->assertNotNull($other->activated_at);
        $this->assertSame($admin->id, $other->activated_by);

        $repository->makeDefault($other);
        $this->assertTrue($other->refresh()->is_default);
        $this->assertFalse($default->refresh()->is_default);
    }

    public function test_settings_audit_repository_records_and_filters_entries(): void
    {
        $admin = Admin::factory()->create();
        $otherAdmin = Admin::factory()->create();

        $repository = $this->app->make(SettingsAuditRepositoryInterface::class);

        $repository->record([
            'category' => 'tax',
            'key' => 'rate',
            'old_value' => 0,
            'new_value' => 5,
            'changed_by' => $admin->id,
            'ip_address' => '10.0.0.1',
        ]);
        SettingsAuditLog::factory()->create(['category' => 'company', 'changed_by' => $otherAdmin->id]);

        $this->assertCount(2, $repository->filtered([]));
        $this->assertCount(1, $repository->filtered(['category' => 'tax']));
        $this->assertCount(1, $repository->filtered(['changed_by' => $otherAdmin->id]));
        $this->assertCount(2, $repository->filtered(['from' => now()->subDay()->toDateTimeString()]));
        $this->assertCount(0, $repository->filtered(['to' => now()->subDay()->toDateTimeString()]));
    }

    public function test_branch_seeder_creates_the_v1_branches(): void
    {
        $this->seed(BranchSeeder::class);

        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseHas('branches', [
            'code' => 'MGQ',
            'name' => 'Mogadishu',
            'status' => BranchStatus::Active->value,
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('branches', [
            'code' => 'HGA',
            'name' => 'Hargeisa',
            'status' => BranchStatus::ComingSoon->value,
            'is_default' => false,
        ]);
    }

    public function test_system_settings_seeder_seeds_every_registry_key_with_defaults(): void
    {
        $this->seed(SystemSettingsSeeder::class);

        $expected = 0;
        foreach (SettingsRegistry::definitions() as $keys) {
            $expected += count($keys);
        }

        $this->assertDatabaseCount('system_settings', $expected);

        $currencyDefault = SystemSetting::query()
            ->category('currency')->where('key', 'default')->sole();
        $this->assertSame('USD', $currencyDefault->value);
        $this->assertSame('USD', $currencyDefault->default_value);

        $smtpPassword = SystemSetting::query()
            ->category('smtp')->where('key', 'password')->sole();
        $this->assertTrue($smtpPassword->is_sensitive);
    }

    public function test_seeders_are_idempotent_and_preserve_existing_values(): void
    {
        $this->seed(BranchSeeder::class);
        $this->seed(SystemSettingsSeeder::class);

        SystemSetting::query()
            ->category('company')->where('key', 'name')
            ->update(['value' => json_encode('Customized')]);

        $this->seed(BranchSeeder::class);
        $this->seed(SystemSettingsSeeder::class);

        $this->assertDatabaseCount('branches', 2);
        $this->assertSame(
            'Customized',
            SystemSetting::query()->category('company')->where('key', 'name')->sole()->value,
        );
    }
}
