<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\HrPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Approved rule: the HR Manager (and the Super Admin) may change the multi-company policy - nobody else, whatever other permissions they hold -
 * enforced by the server on every call and audited with who, before/after and why. The rule must not widen any other permission.
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

    public function test_the_catalogue_names_exactly_the_super_admin_and_the_hr_manager_and_no_other_policy_changes(): void
    {
        $this->assertSame([HrPolicy::MULTI_COMPANY_ASSIGNMENTS], array_keys(HrPolicy::CATALOG), 'no other policy exists, so none was opened to the HR Manager');
        $this->assertSame([AdminRole::SuperAdmin, AdminRole::HrManager], HrPolicy::CATALOG[HrPolicy::MULTI_COMPANY_ASSIGNMENTS]['changeable_by']);

        foreach (AdminRole::cases() as $role) {
            $expected = in_array($role, [AdminRole::SuperAdmin, AdminRole::HrManager], true);
            $this->assertSame($expected, HrPolicy::roleMayChange(HrPolicy::MULTI_COMPANY_ASSIGNMENTS, $role), $role->value);
        }
    }

    public function test_the_hr_manager_with_hr_manage_changes_it_and_the_change_is_recorded_with_actor_values_and_reason(): void
    {
        $hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);

        $this->as($hrManager)->putJson(self::URL, ['enabled' => true, 'reason' => '  A cleaner works two sites  '])->assertOk()->assertJsonPath('data.enabled', true);

        $row = HrPolicy::query()->where('key', HrPolicy::MULTI_COMPANY_ASSIGNMENTS)->sole();
        $this->assertTrue($row->enabled);
        $this->assertNotNull($row->changed_by);
        $this->assertSame('A cleaner works two sites', $row->reason, 'the reason is stored trimmed');

        $log = AuditLog::query()->where('entity_type', HrPolicy::class)->sole();
        $this->assertSame($row->changed_by, $log->admin_id);
        $this->assertSame('update', $log->action->value ?? $log->action);
        $this->assertSame([false, true, 'A cleaner works two sites'], [$log->metadata['before'], $log->metadata['after'], $log->metadata['reason']]);
        $this->assertStringContainsString('ON', $log->description);
    }

    public function test_the_super_admin_keeps_the_same_ability_with_the_same_audit_trail(): void
    {
        [$admin, $token] = $this->superAdminToken();

        $this->as($token)->putJson(self::URL, ['enabled' => true, 'reason' => 'Owner decision to allow two companies'])->assertOk();

        $this->assertTrue(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS));
        $this->assertSame($admin->id, AuditLog::query()->where('entity_type', HrPolicy::class)->sole()->admin_id);
    }

    public function test_every_other_role_is_refused_by_the_server_even_when_it_holds_hr_manage_directly(): void
    {
        $others = [AdminRole::Manager, AdminRole::Sales, AdminRole::Inventory, AdminRole::Accountant, AdminRole::HrEmployee, AdminRole::MarketingManager, AdminRole::MarketingEmployee, AdminRole::MobileAppManager];

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

        $hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $this->as($hrManager)->putJson('/api/v1/admin/hr/policies/some_other_policy', ['enabled' => true, 'reason' => 'Not a real policy'])->assertNotFound();
        $this->as($hrManager)->putJson(self::URL, ['enabled' => true, 'reason' => 'abc'])->assertUnprocessable();

        $this->assertFalse(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS));
        $this->assertSame(0, AuditLog::query()->where('entity_type', HrPolicy::class)->count());
    }

    public function test_switching_to_the_value_it_already_has_is_refused_so_the_audit_trail_holds_only_real_changes(): void
    {
        $hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);

        $this->as($hrManager)->putJson(self::URL, ['enabled' => false, 'reason' => 'It is already off'])->assertUnprocessable()->assertJsonPath('error_code', 'HR_POLICY_UNCHANGED');
        $this->as($hrManager)->putJson(self::URL, ['enabled' => true, 'reason' => 'First real change'])->assertOk();
        $this->as($hrManager)->putJson(self::URL, ['enabled' => true, 'reason' => 'Same again'])->assertUnprocessable()->assertJsonPath('error_code', 'HR_POLICY_UNCHANGED');

        $this->assertSame(1, AuditLog::query()->where('entity_type', HrPolicy::class)->count());
    }
}
