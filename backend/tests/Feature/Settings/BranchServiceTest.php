<?php

namespace Tests\Feature\Settings;

use App\Contracts\Settings\Services\BranchServiceInterface;
use App\Contracts\Settings\Services\SettingsServiceInterface;
use App\Enums\Settings\BranchStatus;
use App\Exceptions\Settings\BranchNotActiveException;
use App\Models\Admin;
use App\Models\Branch;
use Database\Seeders\BranchSeeder;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BranchSeeder::class);
        $this->seed(SystemSettingsSeeder::class);
    }

    public function test_all_returns_the_seeded_branches(): void
    {
        $branches = $this->service()->all();

        $this->assertSame(['MGQ', 'HGA'], $branches->pluck('code')->all());
    }

    public function test_activate_marks_the_branch_active_and_audits_the_change(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $hargeisa = Branch::query()->where('code', 'HGA')->sole();

        $activated = $this->service()->activate($hargeisa, $admin, '10.0.0.1');

        $this->assertSame(BranchStatus::Active, $activated->status);
        $this->assertNotNull($activated->activated_at);
        $this->assertSame($admin->id, $activated->activated_by);

        $this->assertDatabaseHas('settings_audit_logs', [
            'category' => 'branch',
            'key' => 'status',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_a_coming_soon_branch_cannot_become_the_default(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $hargeisa = Branch::query()->where('code', 'HGA')->sole();

        $this->expectException(BranchNotActiveException::class);

        $this->service()->makeDefault($hargeisa, $admin, null);
    }

    public function test_make_default_switches_the_default_branch_and_updates_the_pointer(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $hargeisa = Branch::query()->where('code', 'HGA')->sole();

        $this->service()->activate($hargeisa, $admin, null);
        $default = $this->service()->makeDefault($hargeisa, $admin, null);

        $this->assertTrue($default->is_default);
        $this->assertFalse(Branch::query()->where('code', 'MGQ')->sole()->is_default);
        $this->assertSame(1, Branch::query()->where('is_default', true)->count());

        $settings = $this->app->make(SettingsServiceInterface::class);
        $this->assertSame('HGA', $settings->value('branch.default'));

        $this->assertDatabaseHas('settings_audit_logs', [
            'category' => 'branch',
            'key' => 'default',
        ]);
    }

    private function service(): BranchServiceInterface
    {
        return $this->app->make(BranchServiceInterface::class);
    }
}
