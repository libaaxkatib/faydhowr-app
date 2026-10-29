<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\HrPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision 2026-10-29: only the Super Admin sees and changes the multi-company policy. The HR Manager does not see the setting and the server
 * refuses the HR Manager's change (this replaces the Phase 2 rule that let the HR Manager switch it). Every change is audited with who, before/after
 * and why, and the rule must not widen any other permission.
 */
class MultiCompanyPolicyAuthorizationTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/hr/policies/multi_company_assignments';

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_the_catalogue_names_exactly_the_super_admin_and_no_other_policy_changes(): void
    {
        $this->assertSame([HrPolicy::MULTI_COMPANY_ASSIGNMENTS], array_keys(HrPolicy::CATALOG), 'no other policy exists, so none was opened to anyone');
        $this->assertSame([AdminRole::SuperAdmin], HrPolicy::CATALOG[HrPolicy::MULTI_COMPANY_ASSIGNMENTS]['changeable_by']);

        foreach (AdminRole::cases() as $role) {
            $this->assertSame($role === AdminRole::SuperAdmin, HrPolicy::roleMayChange(HrPolicy::MULTI_COMPANY_ASSIGNMENTS, $role), $role->value);
        }
    }

    public function test_the_hr_manager_cannot_change_it_even_with_hr_manage_and_nothing_is_recorded(): void
    {
        $hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);

        $this->as($hrManager)->putJson(self::URL, ['enabled' => true, 'reason' => 'A cleaner works two sites'])->assertForbidden()->assertJsonPath('error_code', 'FORBIDDEN');

        $this->assertFalse(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS), 'the value is untouched');
        $this->assertSame(0, HrPolicy::query()->count());
        $this->assertSame(0, AuditLog::query()->where('entity_type', HrPolicy::class)->count());
    }

    public function test_the_hr_manager_cannot_turn_it_off_once_the_super_admin_has_turned_it_on(): void
    {
        [, $super] = $this->superAdminToken();
        $hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);

        $this->as($super)->putJson(self::URL, ['enabled' => true, 'reason' => 'Owner allows two companies'])->assertOk();
        $this->as($hrManager)->putJson(self::URL, ['enabled' => false, 'reason' => 'Switching it off myself'])->assertForbidden();

        $this->assertTrue(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS), 'the Super Admin\'s value stays as it is');
        $this->assertSame(1, AuditLog::query()->where('entity_type', HrPolicy::class)->count());
    }

    public function test_the_super_admin_changes_it_and_the_change_is_recorded_with_actor_values_and_reason(): void
    {
        [$admin, $token] = $this->superAdminToken();

        $this->as($token)->putJson(self::URL, ['enabled' => true, 'reason' => '  Owner decision to allow two companies  '])->assertOk()->assertJsonPath('data.enabled', true);

        $row = HrPolicy::query()->where('key', HrPolicy::MULTI_COMPANY_ASSIGNMENTS)->sole();
        $this->assertTrue($row->enabled);
        $this->assertSame($admin->id, (int) $row->changed_by);
        $this->assertSame('Owner decision to allow two companies', $row->reason, 'the reason is stored trimmed');

        $log = AuditLog::query()->where('entity_type', HrPolicy::class)->sole();
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame('update', $log->action->value ?? $log->action);
        $this->assertSame([false, true, 'Owner decision to allow two companies'], [$log->metadata['before'], $log->metadata['after'], $log->metadata['reason']]);
        $this->assertStringContainsString('ON', $log->description);
    }

    public function test_every_other_role_is_refused_by_the_server_even_when_it_holds_hr_manage_directly(): void
    {
        $others = [AdminRole::HrManager, AdminRole::Manager, AdminRole::Sales, AdminRole::Inventory, AdminRole::Accountant, AdminRole::HrEmployee, AdminRole::MarketingManager, AdminRole::MarketingEmployee, AdminRole::MobileAppManager];

        foreach ($others as $role) {
            $token = $this->tokenForRoleWith($role, ['hr.view', 'hr.manage', 'hr.inspections.manage', 'hr.reports.view']);
            $this->as($token)->putJson(self::URL, ['enabled' => true, 'reason' => 'Direct API call from '.$role->value])->assertForbidden();
        }

        $this->assertFalse(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS));
        $this->assertSame(0, AuditLog::query()->where('entity_type', HrPolicy::class)->count(), 'a refused call leaves no change and no audit row');
    }

    public function test_the_hr_manager_role_without_hr_manage_is_refused_and_the_new_inspection_permissions_do_not_widen_it(): void
    {
        // The Phase 2 permissions (dashboard, inspections) must not be a back door to this switch.
        $token = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.dashboard.view', 'hr.inspections.view', 'hr.inspections.manage', 'hr.reports.view']);

        $this->as($token)->putJson(self::URL, ['enabled' => true, 'reason' => 'Should not be allowed'])->assertForbidden();
        $this->assertFalse(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS));
    }

    public function test_anonymous_and_unknown_policy_calls_are_refused_and_nothing_changes(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->putJson(self::URL, ['enabled' => true, 'reason' => 'Nobody is signed in'])->assertUnauthorized();

        [, $super] = $this->superAdminToken();
        $this->as($super)->putJson('/api/v1/admin/hr/policies/some_other_policy', ['enabled' => true, 'reason' => 'Not a real policy'])->assertNotFound();
        $this->as($super)->putJson(self::URL, ['enabled' => true, 'reason' => 'abc'])->assertUnprocessable();

        $this->assertFalse(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS));
        $this->assertSame(0, AuditLog::query()->where('entity_type', HrPolicy::class)->count());
    }

    public function test_switching_to_the_value_it_already_has_is_refused_so_the_audit_trail_holds_only_real_changes(): void
    {
        [, $super] = $this->superAdminToken();

        $this->as($super)->putJson(self::URL, ['enabled' => false, 'reason' => 'It is already off'])->assertUnprocessable()->assertJsonPath('error_code', 'HR_POLICY_UNCHANGED');
        $this->as($super)->putJson(self::URL, ['enabled' => true, 'reason' => 'First real change'])->assertOk();
        $this->as($super)->putJson(self::URL, ['enabled' => true, 'reason' => 'Same again'])->assertUnprocessable()->assertJsonPath('error_code', 'HR_POLICY_UNCHANGED');

        $this->assertSame(1, AuditLog::query()->where('entity_type', HrPolicy::class)->count());
    }

    public function test_the_hr_manager_is_not_shown_the_setting_at_all_while_the_super_admin_still_is(): void
    {
        [, $super] = $this->superAdminToken();
        $hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $this->as($super)->putJson(self::URL, ['enabled' => true, 'reason' => 'Owner allows two companies'])->assertOk();

        $forHr = $this->as($hrManager)->getJson('/api/v1/admin/hr/policies')->assertOk()->json('data');
        $this->assertSame([], $forHr['policies'], 'no state, no "last changed by", no switch');
        $this->assertSame(0, $forHr['employees_with_concurrent_assignments'], 'and not how many employees it concerns');

        $forSuper = $this->as($super)->getJson('/api/v1/admin/hr/policies')->assertOk()->json('data');
        $this->assertSame(HrPolicy::MULTI_COMPANY_ASSIGNMENTS, $forSuper['policies'][0]['key']);
        $this->assertTrue($forSuper['policies'][0]['enabled']);
        $this->assertTrue($forSuper['policies'][0]['can_change']);
        $this->assertNotNull($forSuper['policies'][0]['changed_by']);
    }

    public function test_the_rule_the_setting_controls_still_applies_to_the_hr_manager_and_the_stored_value_is_not_touched_by_reading(): void
    {
        [, $super] = $this->superAdminToken();
        $hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $this->as($super)->putJson(self::URL, ['enabled' => true, 'reason' => 'Owner allows two companies'])->assertOk();

        $this->as($hrManager)->getJson('/api/v1/admin/hr/policies')->assertOk();

        $this->assertTrue(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS), 'withholding the card from the HR Manager does not change the setting');
        $this->assertSame(1, HrPolicy::query()->count());
    }
}
