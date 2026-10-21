<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Salary visibility - the approved boundary (owner decision "hr_view", 2026-10-19).
 *
 * Pay data (salary, payments, penalties, advances, payroll figures) is readable by holders of `hr.view` and by nobody
 * who lacks it: no new salary key exists. Payroll runs, the monthly payment list, replacement cost and imports keep
 * their own, stricter permissions. This file is the executable inventory of every route that returns pay data
 * (`HR_P1_PERMISSION_MATRIX_AND_ROLE_BASELINE_2026-10-19.md`, rows 1-16) and proves each one against the boundary:
 * a role or grant set without `hr.view` is refused, including `hr.reports.view` on its own and every non-HR role.
 */
class SalaryExposureInventoryTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function tokenForRole(AdminRole $role, array $keys): string
    {
        $admin = Admin::factory()->create(['role' => $role]);

        foreach ($keys as $key) {
            DB::table('admin_permissions')->insert([
                'admin_id' => $admin->id,
                'permission_id' => Permission::query()->where('key', $key)->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $admin->createToken('t')->plainTextToken;
    }

    /** Every GET route that returns salary, payment, penalty, advance or payroll amounts and is gated by hr.view (matrix rows 1-5). */
    private function hrViewPayRoutes(int $employeeId, int $assignmentId): array
    {
        return [
            '/api/v1/admin/hr/employees',
            "/api/v1/admin/hr/employees/{$employeeId}",
            "/api/v1/admin/hr/employees/{$employeeId}/payroll-summary?work_assignment_id={$assignmentId}&period=2026-10",
            '/api/v1/admin/hr/dashboard',
            '/api/v1/admin/hr/temporary-replacements',
        ];
    }

    /** Pay-bearing reports (matrix rows 6-9). */
    private const PAY_REPORTS = [
        '/api/v1/admin/hr/reports/financial-ledger',
        '/api/v1/admin/hr/reports/payroll-rollup?period=2026-10',
        '/api/v1/admin/hr/reports/temporary-replacements',
    ];

    /** Stricter, separately-permissioned money and import routes (matrix rows 13-16). */
    private const SEPARATED = [
        '/api/v1/admin/hr/payroll-runs',
        '/api/v1/admin/hr/reports/monthly-payment-list',
        '/api/v1/admin/hr/temporary-replacements/1/cost',
        '/api/v1/admin/hr/imports',
    ];

    private const PAY_SECTIONS = [
        'company_salary_totals',
        'payment_totals_by_department',
        'penalty_totals_by_department',
        'advance_totals_by_department',
        'replacement_totals_by_company',
    ];

    // ------------------------------------------------ the boundary holds

    public function test_hr_view_holders_read_salary_as_the_approved_boundary_allows(): void
    {
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->makeCompany(), null, 275);
        $viewer = $this->tokenWithOnly(['hr.view', 'hr.dashboard.view']);

        $list = $this->asToken($viewer)->getJson('/api/v1/admin/hr/employees')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $employee->id);
        $this->assertSame(275.0, (float) ($row['current_salary']['amount'] ?? 0));

        $record = $this->asToken($viewer)->getJson("/api/v1/admin/hr/employees/{$employee->id}")->assertOk();
        $this->assertSame(275.0, (float) $record->json('data.current_salary.amount'));

        $this->asToken($viewer)
            ->getJson("/api/v1/admin/hr/employees/{$employee->id}/payroll-summary?work_assignment_id={$assignment->id}&period=2026-10")
            ->assertOk();

        $dashboard = $this->asToken($viewer)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data');
        $this->assertArrayHasKey('month_payments_total', $this->flatten($dashboard));
    }

    public function test_hr_reports_view_alone_never_reveals_pay(): void
    {
        $reports = $this->tokenWithOnly(['hr.reports.view']);

        foreach (self::PAY_REPORTS as $uri) {
            $this->asToken($reports)->getJson($uri)->assertStatus(403);
        }

        $summary = $this->asToken($reports)->getJson('/api/v1/admin/hr/reports/summary')->assertOk()->json('data');
        foreach (self::PAY_SECTIONS as $section) {
            $this->assertArrayNotHasKey($section, $summary, "hr.reports.view alone must not receive $section");
        }
        $this->assertArrayHasKey('status_breakdown', $summary, 'the non-pay sections stay available');
    }

    public function test_hr_reports_view_with_hr_view_receives_the_pay_reports_and_sections(): void
    {
        $both = $this->tokenWithOnly(['hr.reports.view', 'hr.view']);

        foreach (self::PAY_REPORTS as $uri) {
            $this->assertContains($this->asToken($both)->getJson($uri)->getStatusCode(), [200, 422], "reports+view must reach $uri");
        }

        $summary = $this->asToken($both)->getJson('/api/v1/admin/hr/reports/summary')->assertOk()->json('data');
        foreach (self::PAY_SECTIONS as $section) {
            $this->assertArrayHasKey($section, $summary);
        }
    }

    public function test_hr_view_alone_does_not_reach_the_reports(): void
    {
        $viewer = $this->tokenWithOnly(['hr.view']);

        foreach ([...self::PAY_REPORTS, '/api/v1/admin/hr/reports/summary'] as $uri) {
            $this->asToken($viewer)->getJson($uri)->assertStatus(403);
        }
    }

    public function test_no_grant_set_without_hr_view_reaches_a_salary_route(): void
    {
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->makeCompany(), null, 300);
        $routes = [...$this->hrViewPayRoutes($employee->id, $assignment->id), ...self::PAY_REPORTS];

        $withoutHrView = [
            'hr.manage only' => [AdminRole::HrManager, ['hr.manage']],
            'hr.reports.view only' => [AdminRole::HrManager, ['hr.reports.view']],
            'payroll + import without hr.view' => [AdminRole::HrManager, ['hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve', 'hr.import']],
            'hr employee, no grants' => [AdminRole::HrEmployee, []],
            'marketing manager baseline' => [AdminRole::MarketingManager, ['marketing.view', 'marketing.manage', 'marketing.assign', 'marketing.reports.view']],
            'marketing employee' => [AdminRole::MarketingEmployee, ['marketing.view']],
            'mobile app manager baseline' => [AdminRole::MobileAppManager, ['dashboard.view', 'customers.view']],
            'manager' => [AdminRole::Manager, ['dashboard.view']],
            'sales' => [AdminRole::Sales, ['dashboard.view']],
            'inventory' => [AdminRole::Inventory, ['dashboard.view']],
            'accountant' => [AdminRole::Accountant, ['dashboard.view']],
        ];

        foreach ($withoutHrView as $label => [$role, $keys]) {
            $token = $this->tokenForRole($role, $keys);

            foreach ($routes as $uri) {
                $this->asToken($token)->getJson($uri)->assertStatus(403, "{$label} must not reach {$uri}");
            }
        }
    }

    public function test_payroll_runs_the_payment_list_replacement_cost_and_imports_refuse_holders_of_only_hr_permissions(): void
    {
        $hrOnly = $this->tokenWithOnly(['hr.view', 'hr.manage', 'hr.reports.view']);

        foreach (self::SEPARATED as $uri) {
            $this->asToken($hrOnly)->getJson($uri)->assertStatus(403);
        }
    }

    public function test_the_monthly_payment_list_needs_payroll_view_and_hr_manage_together(): void
    {
        $uri = '/api/v1/admin/hr/reports/monthly-payment-list';

        $this->asToken($this->tokenWithOnly(['hr.payroll.view']))->getJson($uri)->assertStatus(403);
        $this->asToken($this->tokenWithOnly(['hr.manage']))->getJson($uri)->assertStatus(403);
    }

    public function test_the_approved_hr_manager_set_reaches_every_pay_route(): void
    {
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->makeCompany(), null, 300);
        $token = $this->tokenForRole(AdminRole::HrManager, ['hr.dashboard.view', 'hr.view', 'hr.manage', 'hr.reports.view', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve', 'hr.import']);

        foreach ([...$this->hrViewPayRoutes($employee->id, $assignment->id), ...self::PAY_REPORTS, '/api/v1/admin/hr/payroll-runs', '/api/v1/admin/hr/reports/monthly-payment-list', '/api/v1/admin/hr/imports'] as $uri) {
            $this->assertContains($this->asToken($token)->getJson($uri)->getStatusCode(), [200, 422], "the HR Manager must reach $uri");
        }
    }

    /** @return array<string, mixed> every key of a nested array, flattened (last key wins) */
    private function flatten(mixed $data): array
    {
        $out = [];
        array_walk_recursive($data, function ($value, $key) use (&$out): void {
            $out[$key] = $value;
        });

        return $out;
    }
}
