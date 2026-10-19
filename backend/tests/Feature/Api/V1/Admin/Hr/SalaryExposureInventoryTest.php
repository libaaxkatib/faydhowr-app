<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * P1.1 - an executable inventory of who can read pay data TODAY.
 *
 * Nothing here changes a permission. The first group pins the routes that are already properly separated (payroll runs,
 * the monthly payment list, replacement cost): holders of only HR permissions are refused. The second group documents the
 * known gaps found by the audit - places where broad HR permissions still return amounts. Those assertions describe the
 * current behaviour on purpose: when the owner decides the salary/payroll matrix and it is implemented, each known_gap test
 * must be flipped to assert that the role is refused, which is the proof asked for in the plan.
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

    // ----------------------------------------------------- already separated

    public function test_payroll_runs_the_payment_list_and_replacement_cost_refuse_holders_of_only_hr_permissions(): void
    {
        $hrOnly = $this->tokenWithOnly(['hr.view', 'hr.manage', 'hr.reports.view']);

        foreach ([
            '/api/v1/admin/hr/payroll-runs',
            '/api/v1/admin/hr/reports/monthly-payment-list',
            '/api/v1/admin/hr/temporary-replacements/1/cost',
            '/api/v1/admin/hr/imports',
        ] as $uri) {
            $this->asToken($hrOnly)->getJson($uri)->assertStatus(403);
        }
    }

    public function test_the_monthly_payment_list_needs_payroll_view_and_hr_manage_together(): void
    {
        $uri = '/api/v1/admin/hr/reports/monthly-payment-list';

        $this->asToken($this->tokenWithOnly(['hr.payroll.view']))->getJson($uri)->assertStatus(403);
        $this->asToken($this->tokenWithOnly(['hr.manage']))->getJson($uri)->assertStatus(403);
    }

    // --------------------------------------------------------- KNOWN GAPS

    public function test_known_gap_hr_view_alone_reads_current_salary_in_the_employee_list_and_record(): void
    {
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany(), null, 275);
        $viewer = $this->tokenWithOnly(['hr.view']);

        $list = $this->asToken($viewer)->getJson('/api/v1/admin/hr/employees')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $employee->id);
        $this->assertSame(275.0, (float) ($row['current_salary']['amount'] ?? 0));

        $record = $this->asToken($viewer)->getJson("/api/v1/admin/hr/employees/{$employee->id}")->assertOk();
        $this->assertSame(275.0, (float) $record->json('data.current_salary.amount'));
    }

    public function test_known_gap_hr_view_alone_reads_the_monthly_payroll_summary_of_an_employee(): void
    {
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->makeCompany(), null, 300);
        $viewer = $this->tokenWithOnly(['hr.view']);

        $status = $this->asToken($viewer)
            ->getJson("/api/v1/admin/hr/employees/{$employee->id}/payroll-summary?work_assignment_id={$assignment->id}&period=2026-10")
            ->getStatusCode();

        $this->assertSame(200, $status);
    }

    public function test_known_gap_hr_view_alone_reads_the_money_tiles_of_the_hr_dashboard(): void
    {
        $viewer = $this->tokenWithOnly(['hr.view']);

        $data = $this->asToken($viewer)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data');

        $this->assertArrayHasKey('month_payments_total', $this->flatten($data));
    }

    public function test_known_gap_hr_reports_view_alone_reads_the_financial_ledger_the_payroll_rollup_and_salary_totals(): void
    {
        $reports = $this->tokenWithOnly(['hr.reports.view']);

        $this->asToken($reports)->getJson('/api/v1/admin/hr/reports/financial-ledger')->assertOk();
        $this->asToken($reports)->getJson('/api/v1/admin/hr/reports/payroll-rollup?period=2026-10')->assertStatus(200);
        $summary = $this->asToken($reports)->getJson('/api/v1/admin/hr/reports/summary')->assertOk()->json('data');
        // the pay sections of the summary are part of what hr.reports.view returns today (empty here, filled when assignments exist)
        $this->assertArrayHasKey('company_salary_totals', $summary);
        $this->assertArrayHasKey('payment_totals_by_department', $summary);
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
