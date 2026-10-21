<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminRole;
use App\Models\MarketingTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * BUG-10 and the Marketing equivalent: the Add/Edit controls of the HR and Marketing screens are offered from the person's EFFECTIVE
 * management permission (GET /admin/auth/permissions: hr.manage / marketing.manage, marketing.assign for assignment), never from whether they
 * can open the dashboard. A manager without dashboard access still gets the actions and the server accepts them; a viewer is offered nothing
 * and the server refuses. The dashboard itself stays closed to anyone without its own permission.
 */
class ManagementActionsDoNotDependOnDashboardTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** @return list<string> */
    private function effective(string $token): array
    {
        return collect($this->as($token)->getJson('/api/v1/admin/auth/permissions')->assertOk()->json('data'))->pluck('key')->all();
    }

    public function test_a_marketing_manager_without_any_dashboard_permission_keeps_every_marketing_action(): void
    {
        $token = $this->tokenForRoleWith(AdminRole::MarketingManager, ['marketing.view', 'marketing.manage', 'marketing.assign']);

        // What the panel reads to decide which controls to show.
        $teamsBefore = MarketingTeam::query()->count();
        $keys = $this->effective($token);
        $this->assertContains('marketing.manage', $keys);
        $this->assertContains('marketing.assign', $keys);
        $this->assertNotContains('dashboard.view', $keys);
        $this->assertNotContains('hr.dashboard.view', $keys);

        // No dashboard ...
        $this->as($token)->getJson('/api/v1/admin/dashboard')->assertForbidden();
        // ... and the server still accepts the management action.
        $this->as($token)->postJson('/api/v1/admin/marketing/teams', ['name' => 'Alpha Team'])->assertCreated();
        $this->assertSame($teamsBefore + 1, MarketingTeam::query()->count());
    }

    public function test_a_marketing_viewer_is_offered_no_action_and_the_server_refuses_each_one(): void
    {
        $token = $this->tokenForRoleWith(AdminRole::MarketingManager, ['marketing.view', 'dashboard.view']);

        $teamsBefore = MarketingTeam::query()->count();
        $keys = $this->effective($token);
        $this->assertNotContains('marketing.manage', $keys, 'the panel hides the controls');
        $this->assertNotContains('marketing.assign', $keys);

        $this->as($token)->getJson('/api/v1/admin/marketing/teams')->assertOk();
        $this->as($token)->postJson('/api/v1/admin/marketing/teams', ['name' => 'Nope'])->assertForbidden();
        $this->as($token)->postJson('/api/v1/admin/marketing/xarun', [])->assertForbidden();
        $this->as($token)->postJson('/api/v1/admin/marketing/project', [])->assertForbidden();
        $this->as($token)->postJson('/api/v1/admin/marketing/commission/rates', [])->assertForbidden();
        $this->assertSame($teamsBefore, MarketingTeam::query()->count());
    }

    public function test_assignment_needs_its_own_permission_not_just_management(): void
    {
        $manageOnly = $this->tokenForRoleWith(AdminRole::MarketingManager, ['marketing.view', 'marketing.manage']);
        $assignOnly = $this->tokenForRoleWith(AdminRole::MarketingManager, ['marketing.view', 'marketing.assign']);

        $this->assertNotContains('marketing.assign', $this->effective($manageOnly));
        $this->as($manageOnly)->patchJson('/api/v1/admin/marketing/records/999/assign', [])->assertForbidden();

        $this->assertContains('marketing.assign', $this->effective($assignOnly));
        $this->assertNotContains('marketing.manage', $this->effective($assignOnly));
        // Past authorization: the record does not exist, which is a 404/422 - never a 403.
        $this->assertNotSame(403, $this->as($assignOnly)->patchJson('/api/v1/admin/marketing/records/999/assign', [])->getStatusCode());
    }

    public function test_an_hr_manager_without_the_dashboard_permission_keeps_every_hr_action_and_a_viewer_gets_none(): void
    {
        $manager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $viewer = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'dashboard.view', 'hr.dashboard.view']);

        $keys = $this->effective($manager);
        $this->assertContains('hr.manage', $keys);
        $this->assertNotContains('hr.dashboard.view', $keys);
        $this->as($manager)->getJson('/api/v1/admin/hr/dashboard')->assertForbidden();
        $this->as($manager)->postJson('/api/v1/admin/hr/positions', ['name' => 'Night Guard'])->assertCreated();

        $this->assertNotContains('hr.manage', $this->effective($viewer), 'a viewer with dashboard access is offered no HR action');
        $this->as($viewer)->getJson('/api/v1/admin/hr/dashboard')->assertOk();
        $this->as($viewer)->postJson('/api/v1/admin/hr/positions', ['name' => 'Day Guard'])->assertForbidden();
    }
}
