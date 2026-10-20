<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Models\PayrollRun;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Permission matrix of every endpoint the HR & Marketing release candidate added, checked against the approved role baselines (not
 * against hand-picked permission lists): who may reach it, who may not, and that an anonymous caller is turned away. The decision is the
 * server's; the screens only mirror it.
 */
class Rc6PermissionMatrixTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    /** Approved baselines (see RoleBaselineIsolationTest). */
    private const BASELINE = [
        'hr_manager' => ['hr.view', 'hr.manage', 'hr.reports.view', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve', 'hr.import'],
        'marketing_manager' => ['marketing.view', 'marketing.manage', 'marketing.assign', 'marketing.commission.view', 'marketing.reports.view'],
        'mobile_app_manager' => ['dashboard.view', 'customers.view'],
        'hr_viewer' => ['hr.view'],
        'hr_reports_only' => ['hr.view', 'hr.reports.view'],
        'payroll_viewer' => ['hr.view', 'hr.payroll.view'],
        // Holds the permission but not the HR Manager role: the role check of the debt/underpayment actions must still refuse it.
        'payroll_manager_wrong_role' => ['hr.view', 'hr.manage', 'hr.payroll.view', 'hr.payroll.manage'],
    ];

    private const ROLE = [
        'hr_manager' => AdminRole::HrManager,
        'marketing_manager' => AdminRole::MarketingManager,
        'mobile_app_manager' => AdminRole::MobileAppManager,
        'hr_viewer' => AdminRole::Sales,
        'hr_reports_only' => AdminRole::Sales,
        'payroll_viewer' => AdminRole::Sales,
        'payroll_manager_wrong_role' => AdminRole::Sales,
    ];

    /** @var array<string, string> */
    private array $tokens = [];

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Matrix Co');
        [, $this->tokens['super_admin']] = $this->superAdminToken();

        foreach (self::BASELINE as $name => $keys) {
            $admin = Admin::factory()->create(['role' => self::ROLE[$name]]);

            foreach ($keys as $key) {
                DB::table('admin_permissions')->insert(['admin_id' => $admin->id, 'permission_id' => Permission::query()->where('key', $key)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
            }

            $this->tokens[$name] = $admin->createToken('t')->plainTextToken;
        }
    }

    private function httpStatus(?string $who, string $method, string $uri, array $body = []): int
    {
        $this->app['auth']->forgetGuards();
        $request = $who === null ? $this->flushHeaders() : $this->withToken($this->tokens[$who]);

        return $request->json($method, $uri, $body)->getStatusCode();
    }

    /**
     * @param  list<string>  $allowed  callers that must get past authorization (any status except 401/403)
     */
    private function assertMatrix(string $method, string $uri, array $allowed, array $body = []): void
    {
        $everyone = [...array_keys($this->tokens)];

        foreach ($everyone as $who) {
            $status = $this->httpStatus($who, $method, $uri, $body);

            if (in_array($who, $allowed, true)) {
                $this->assertNotContains($status, [401, 403], "{$who} must be let in to {$method} {$uri} (got {$status})");
                $this->assertLessThan(500, $status, "{$method} {$uri} as {$who} must not crash");
            } else {
                $this->assertSame(403, $status, "{$who} must be refused on {$method} {$uri} (got {$status})");
            }
        }

        $this->assertSame(401, $this->httpStatus(null, $method, $uri, $body), "anonymous must get 401 on {$method} {$uri}");
    }

    public function test_the_policy_switch_is_visible_to_hr_viewers_and_changeable_only_by_the_super_admin(): void
    {
        $this->assertMatrix('GET', '/api/v1/admin/hr/policies', ['super_admin', 'hr_manager', 'hr_viewer', 'hr_reports_only', 'payroll_viewer', 'payroll_manager_wrong_role']);
        $this->assertMatrix('PUT', '/api/v1/admin/hr/policies/multi_company_assignments', ['super_admin'], ['enabled' => false, 'reason' => 'Matrix test, no change']);
    }

    public function test_the_company_employee_screen_needs_hr_view_and_its_actions_need_hr_manage(): void
    {
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $base = "/api/v1/admin/hr/client-companies/{$this->company->id}/employees";
        $viewers = ['super_admin', 'hr_manager', 'hr_viewer', 'hr_reports_only', 'payroll_viewer', 'payroll_manager_wrong_role'];

        $this->assertMatrix('GET', $base, $viewers);
        $this->assertMatrix('GET', "/api/v1/admin/hr/client-companies/{$this->company->id}/employee-history", $viewers);
        $this->assertMatrix('PUT', "{$base}/{$assignment->id}", ['super_admin', 'hr_manager', 'payroll_manager_wrong_role'], ['notes' => 'matrix']);
        $this->assertMatrix('POST', "{$base}/{$assignment->id}/stop", ['super_admin', 'hr_manager', 'payroll_manager_wrong_role'], ['end_date' => '2099-01-01', 'reason' => 'x']);
        $this->assertMatrix('POST', "{$base}/{$assignment->id}/replace", ['super_admin', 'hr_manager', 'payroll_manager_wrong_role'], []);
        $this->assertMatrix('POST', "{$base}/{$assignment->id}/return", ['super_admin', 'hr_manager', 'payroll_manager_wrong_role'], []);
        $this->assertSame('active', EmployeeWorkAssignment::query()->findOrFail($assignment->id)->status->value, 'no probe changed the assignment');
    }

    public function test_the_corrections_ledger_needs_payroll_view_and_its_transactions_need_payroll_manage_plus_the_right_role(): void
    {
        $employee = $this->makeEmployee();
        $run = PayrollRun::query()->create(['period' => '2026-09', 'status' => 'finalized']);
        $debt = PayrollCorrection::query()->create([
            'batch_key' => (string) Str::uuid(), 'employee_id' => $employee->id, 'payroll_run_id' => $run->id, 'period' => '2026-09', 'kind' => 'overpayment', 'action' => 'edited',
            'recorded_net_cents' => 10000, 'corrected_net_cents' => 0, 'difference_cents' => 10000, 'outstanding_cents' => 10000, 'status' => 'open', 'reason' => 'matrix',
        ]);
        $owed = PayrollCorrection::query()->create([
            'batch_key' => (string) Str::uuid(), 'employee_id' => $employee->id, 'payroll_run_id' => $run->id, 'period' => '2026-09', 'kind' => 'underpayment', 'action' => 'edited',
            'recorded_net_cents' => 0, 'corrected_net_cents' => 10000, 'difference_cents' => -10000, 'outstanding_cents' => 10000, 'status' => 'open', 'reason' => 'matrix',
        ]);

        $this->assertMatrix('GET', '/api/v1/admin/hr/payroll-corrections', ['super_admin', 'hr_manager', 'payroll_viewer', 'payroll_manager_wrong_role']);

        // Reaching the controller needs hr.payroll.manage; the ACTION then checks the role, so the payment of an underpayment is Super Admin only.
        $repayment = ['type' => 'debt_repayment', 'amount' => 1, 'transaction_date' => '2026-10-01'];
        $payment = ['type' => 'underpayment_payment', 'amount' => 1, 'transaction_date' => '2026-10-01'];

        foreach (self::BASELINE + ['super_admin' => []] as $who => $_) {
            $debtStatus = $this->httpStatus($who, 'POST', "/api/v1/admin/hr/payroll-corrections/{$debt->id}/transactions", $repayment + ['idempotency_key' => "debt-{$who}-key"]);
            $payStatus = $this->httpStatus($who, 'POST', "/api/v1/admin/hr/payroll-corrections/{$owed->id}/transactions", $payment + ['idempotency_key' => "pay-{$who}-key"]);

            $mayManageDebt = in_array($who, ['super_admin', 'hr_manager'], true);
            $mayPay = $who === 'super_admin';

            $this->assertSame($mayManageDebt ? 201 : 403, $debtStatus, "{$who}: debt repayment");
            $this->assertSame($mayPay ? 201 : 403, $payStatus, "{$who}: underpayment payment");
        }

        $this->assertSame(2, $debt->transactions()->count(), 'only the Super Admin and the HR Manager recorded a repayment');
        $this->assertSame(1, $owed->transactions()->count(), 'only the Super Admin recorded a payment');
        $this->assertSame(401, $this->httpStatus(null, 'POST', "/api/v1/admin/hr/payroll-corrections/{$debt->id}/transactions", $repayment + ['idempotency_key' => 'anon-key']));
    }

    public function test_hr_and_marketing_stay_separate_and_the_mobile_app_manager_reaches_neither(): void
    {
        $this->assertMatrix('GET', '/api/v1/admin/marketing/records', ['super_admin', 'marketing_manager']);
        $this->assertMatrix('GET', '/api/v1/admin/hr/payroll-runs', ['super_admin', 'hr_manager', 'payroll_viewer', 'payroll_manager_wrong_role']);
    }
}
