<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\HrPolicy;
use App\Models\Permission;
use App\Support\AdminPermissionResolver;
use App\Support\Hr\Payroll\PaymentNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Phase 2 (owner decisions 4 and 7): the HR Dashboard has its own permission (enforced by the API, not only by hiding the menu), the HR Manager
 * may switch the multi-company policy (audited, never automatic), employee categories can be created and renamed with duplicate protection, a
 * manually typed payment number is recorded as manual, and the migration that introduces the permissions keeps today's access unchanged.
 */
class Phase2HrAccessTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    // ------------------------------------------------------------------ E: HR Dashboard permission

    public function test_the_hr_dashboard_needs_its_own_permission_on_the_server(): void
    {
        $url = '/api/v1/admin/hr/dashboard';
        $viewOnly = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.reports.view']);
        $withDashboard = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.dashboard.view']);
        [, $super] = $this->superAdminToken();

        $this->as($viewOnly)->getJson($url)->assertForbidden();
        $this->as($withDashboard)->getJson($url)->assertOk();
        $this->as($super)->getJson($url)->assertOk();
        $this->as($this->noPermissionToken())->getJson($url)->assertForbidden();
        // Every other HR read page is still reachable with hr.view alone: only the dashboard was split off.
        $this->as($viewOnly)->getJson('/api/v1/admin/hr/employees')->assertOk();
    }

    public function test_the_permission_is_in_the_catalogue_and_super_admin_holds_it_implicitly(): void
    {
        foreach (['hr.dashboard.view', 'hr.inspections.view', 'hr.inspections.manage'] as $key) {
            $this->assertDatabaseHas('permissions', ['key' => $key, 'group' => 'HR']);
        }
        [$admin] = $this->superAdminToken();

        $keys = app(AdminPermissionResolver::class)->keysFor($admin, request());

        $this->assertContains('hr.dashboard.view', $keys);
    }

    public function test_the_migration_keeps_access_unchanged_it_only_extends_a_role_that_already_holds_the_base_permission(): void
    {
        $migration = require base_path('database/migrations/2026_10_21_100000_add_hr_dashboard_and_inspection_permissions.php');
        $id = fn (string $key): int => (int) Permission::query()->where('key', $key)->value('id');
        $grant = fn (string $role, string $key) => DB::table('admin_role_permissions')->insert(['role' => $role, 'permission_id' => $id($key), 'created_at' => now(), 'updated_at' => now()]);
        $has = fn (string $role, string $key): bool => DB::table('admin_role_permissions')->where('role', $role)->where('permission_id', $id($key))->exists();

        // A role that has no baseline gets nothing.
        $migration->up();
        $this->assertFalse($has('hr_manager', 'hr.dashboard.view'));
        $this->assertFalse($has('hr_manager', 'hr.inspections.view'));

        // The HR Manager role as in production: hr.view + hr.manage at role level.
        $grant('hr_manager', 'hr.view');
        $grant('hr_manager', 'hr.manage');
        $migration->up();
        $this->assertTrue($has('hr_manager', 'hr.dashboard.view'));
        $this->assertTrue($has('hr_manager', 'hr.inspections.view'));
        $this->assertTrue($has('hr_manager', 'hr.inspections.manage'));
        $this->assertFalse($has('manager', 'hr.dashboard.view'), 'no other role is touched');
        $before = DB::table('admin_role_permissions')->count();
        $migration->up();
        $this->assertSame($before, DB::table('admin_role_permissions')->count(), 'running it again changes nothing');
    }

    // ------------------------------------------------------------------ F: HR Manager and the policy switch

    public function test_the_hr_manager_can_switch_the_multi_company_policy_and_it_is_audited(): void
    {
        $url = '/api/v1/admin/hr/policies/'.HrPolicy::MULTI_COMPANY_ASSIGNMENTS;
        $hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);

        $this->assertFalse(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS), 'nothing switches it by itself');

        $this->as($hrManager)->putJson($url, ['enabled' => true, 'reason' => 'A cleaner works two sites'])->assertOk();
        $this->assertTrue(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS));
        $this->as($hrManager)->putJson($url, ['enabled' => false, 'reason' => 'Back to one company each'])->assertOk();
        $this->assertFalse(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS));

        $logs = AuditLog::query()->where('entity_type', HrPolicy::class)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame([false, true, 'A cleaner works two sites'], [$logs[0]->metadata['before'], $logs[0]->metadata['after'], $logs[0]->metadata['reason']]);
        $this->assertSame([true, false], [$logs[1]->metadata['before'], $logs[1]->metadata['after']]);
        $this->assertNotNull($logs[0]->admin_id, 'the actor is recorded');
        $this->assertNotNull($logs[0]->created_at, 'and the time');
    }

    public function test_other_roles_and_a_missing_reason_are_still_refused_for_the_policy_switch(): void
    {
        $url = '/api/v1/admin/hr/policies/'.HrPolicy::MULTI_COMPANY_ASSIGNMENTS;
        $hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $hrViewOnly = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view']);
        $marketing = $this->tokenForRoleWith(AdminRole::MarketingManager, ['hr.view', 'hr.manage']);
        $sales = $this->tokenForRoleWith(AdminRole::Sales, ['hr.view', 'hr.manage']);

        $this->as($hrViewOnly)->putJson($url, ['enabled' => true, 'reason' => 'I would like it'])->assertForbidden();
        $this->as($marketing)->putJson($url, ['enabled' => true, 'reason' => 'I would like it'])->assertForbidden();
        $this->as($sales)->putJson($url, ['enabled' => true, 'reason' => 'I would like it'])->assertForbidden();
        $this->as($hrManager)->putJson($url, ['enabled' => true])->assertUnprocessable();
        $this->as($hrManager)->putJson('/api/v1/admin/hr/policies/unknown_policy', ['enabled' => true, 'reason' => 'whatever happens'])->assertNotFound();
        $this->assertFalse(HrPolicy::isEnabled(HrPolicy::MULTI_COMPANY_ASSIGNMENTS));
    }

    public function test_the_policy_list_tells_each_role_whether_it_may_change_it(): void
    {
        $hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $viewer = $this->tokenForRoleWith(AdminRole::Sales, ['hr.view']);
        [, $super] = $this->superAdminToken();

        $this->assertTrue($this->as($hrManager)->getJson('/api/v1/admin/hr/policies')->assertOk()->json('data.policies.0.can_change'));
        $this->assertTrue($this->as($super)->getJson('/api/v1/admin/hr/policies')->json('data.policies.0.can_change'));
        $this->assertFalse($this->as($viewer)->getJson('/api/v1/admin/hr/policies')->json('data.policies.0.can_change'));
    }

    // ------------------------------------------------------------------ B: categories

    public function test_a_category_can_be_created_audited_and_duplicates_are_refused_however_typed(): void
    {
        $token = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $count = EmployeeCategory::query()->count();

        $created = $this->as($token)->postJson('/api/v1/admin/hr/employee-categories', ['name' => '  Gardening   Team ', 'description' => 'Outdoor work'])
            ->assertCreated()->assertJsonPath('data.name', 'Gardening Team')->json('data');

        $this->assertSame($count + 1, EmployeeCategory::query()->count());
        $this->assertDatabaseHas('audit_logs', ['entity_type' => EmployeeCategory::class, 'entity_id' => $created['id'], 'action' => 'create']);
        foreach (['gardening team', 'GARDENING  TEAM', ' Gardening Team'] as $again) {
            $this->as($token)->postJson('/api/v1/admin/hr/employee-categories', ['name' => $again])->assertUnprocessable()->assertJsonValidationErrors(['name'], 'errors');
        }
        $this->assertSame($count + 1, EmployeeCategory::query()->count());
        $this->as($token)->postJson('/api/v1/admin/hr/employee-categories', ['name' => ''])->assertUnprocessable();
        // The new category shows up in the list the selector uses.
        $this->assertContains('Gardening Team', collect($this->as($token)->getJson('/api/v1/admin/hr/employee-categories')->json('data'))->pluck('name')->all());
    }

    public function test_creating_a_category_needs_hr_manage(): void
    {
        $viewOnly = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view']);

        $this->as($viewOnly)->postJson('/api/v1/admin/hr/employee-categories', ['name' => 'Window Cleaning'])->assertForbidden();
        $this->as($this->noPermissionToken())->postJson('/api/v1/admin/hr/employee-categories', ['name' => 'Window Cleaning'])->assertForbidden();
        $this->assertDatabaseMissing('employee_categories', ['name' => 'Window Cleaning']);
    }

    public function test_renaming_a_category_keeps_its_id_and_every_employee_pointing_at_it_and_the_office_staff_category_is_locked(): void
    {
        $token = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $category = EmployeeCategory::query()->where('name', 'Cooking')->firstOrFail();
        $employee = $this->makeEmployee();
        $employee->update(['employee_category_id' => $category->id]);

        $this->as($token)->putJson("/api/v1/admin/hr/employee-categories/{$category->id}", ['name' => 'Cooking & Kitchen'])->assertOk()->assertJsonPath('data.id', $category->id);

        $this->assertSame($category->id, $employee->fresh()->employee_category_id);
        $this->assertSame('Cooking & Kitchen', $employee->fresh()->category->name);
        $this->as($token)->putJson("/api/v1/admin/hr/employee-categories/{$category->id}", ['name' => 'general cleaning'])->assertUnprocessable();

        $office = EmployeeCategory::officeStaff() ?? EmployeeCategory::query()->create(['name' => EmployeeCategory::OFFICE_STAFF]);
        $this->as($token)->putJson("/api/v1/admin/hr/employee-categories/{$office->id}", ['name' => 'Back Office'])->assertUnprocessable()->assertJsonPath('error_code', 'CATEGORY_NAME_LOCKED');
        $this->assertSame(EmployeeCategory::OFFICE_STAFF, $office->fresh()->name);
    }

    // ------------------------------------------------------------------ D: a typed payment number is "manual"

    public function test_a_payment_number_typed_by_hr_is_recorded_as_manual_and_a_flagged_difference_can_be_accepted(): void
    {
        $token = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $employee = $this->makeEmployee('active', 'permanent', '615000111');
        $employee->forceFill([
            'payment_account_number' => '38628356',
            'payment_account_source' => PaymentNumber::SOURCE_WORKBOOK_PART_ONE,
            'payment_account_source_ref' => 'EMPLOYEE AND COMPANY.xlsx / row 4',
            'payment_account_register_check' => PaymentNumber::CHECK_MISMATCH,
        ])->save();

        // Accepting the difference changes only the review state: the number, the source and the phone stay.
        $this->as($token)->putJson("/api/v1/admin/hr/employees/{$employee->id}", ['accept_payment_account_mismatch' => true])->assertOk();
        $fresh = $employee->fresh();
        $this->assertSame(PaymentNumber::CHECK_ACCEPTED, $fresh->payment_account_register_check);
        $this->assertSame('38628356', $fresh->payment_account_number);
        $this->assertSame(PaymentNumber::SOURCE_WORKBOOK_PART_ONE, $fresh->payment_account_source);

        // Typing a different number makes it an HR-entered one; the phone is untouched and the audit trail holds no number.
        $this->as($token)->putJson("/api/v1/admin/hr/employees/{$employee->id}", ['payment_account_number' => '0612223344'])->assertOk();
        $fresh = $employee->fresh();
        $this->assertSame('0612223344', $fresh->payment_account_number);
        $this->assertSame(PaymentNumber::SOURCE_MANUAL, $fresh->payment_account_source);
        $this->assertNull($fresh->payment_account_register_check);
        $this->assertSame('615000111', $fresh->phone);
        $audit = AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $employee->id)->latest('id')->first();
        $this->assertTrue($audit->metadata['payment_account_number_changed']);
        $this->assertStringNotContainsString('0612223344', json_encode($audit->metadata));
    }

    public function test_a_payment_number_given_at_registration_is_recorded_as_manual_and_none_is_invented(): void
    {
        $token = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $category = EmployeeCategory::query()->firstOrFail();
        $body = ['full_name' => 'Test Person One', 'phone' => '615111222', 'location' => 'Mogadishu', 'gender' => 'female', 'employee_category_id' => $category->id, 'application_date' => now()->toDateString()];

        $with = $this->as($token)->postJson('/api/v1/admin/hr/employees', $body + ['payment_account_number' => '0615111222'])->assertCreated()->json('data.id');
        $without = $this->as($token)->postJson('/api/v1/admin/hr/employees', ['full_name' => 'Test Person Two', 'phone' => '615333444'] + $body)->assertCreated()->json('data.id');

        $this->assertSame([PaymentNumber::SOURCE_MANUAL, '0615111222'], [Employee::find($with)->payment_account_source, Employee::find($with)->payment_account_number]);
        $this->assertNull(Employee::find($without)->payment_account_number, 'no number is invented from the phone');
        $this->assertNull(Employee::find($without)->payment_account_source);
    }
}
