<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\EmployeePayment;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Models\PayrollItem;
use App\Models\PayrollPaymentVoid;
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
        'hr_manager' => ['hr.dashboard.view', 'hr.view', 'hr.manage', 'hr.reports.view', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve', 'hr.import'],
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

    public function test_the_policy_switch_is_visible_to_hr_viewers_and_changeable_by_the_super_admin_and_the_hr_manager(): void
    {
        $this->assertMatrix('GET', '/api/v1/admin/hr/policies', ['super_admin', 'hr_manager', 'hr_viewer', 'hr_reports_only', 'payroll_viewer', 'payroll_manager_wrong_role']);
        $this->assertMatrix('PUT', '/api/v1/admin/hr/policies/multi_company_assignments', ['super_admin', 'hr_manager'], ['enabled' => false, 'reason' => 'Matrix test, no change']);
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

            $writeOff = $this->httpStatus($who, 'POST', "/api/v1/admin/hr/payroll-corrections/{$debt->id}/transactions", ['type' => 'debt_write_off', 'amount' => 1, 'reason' => 'Matrix test write-off', 'transaction_date' => '2026-10-01', 'idempotency_key' => "wo-{$who}-key"]);
            $this->assertSame($who === 'super_admin' ? 201 : 403, $writeOff, "{$who}: debt write-off");

            $mayManageDebt = in_array($who, ['super_admin', 'hr_manager'], true);
            $mayPay = $who === 'super_admin';

            $this->assertSame($mayManageDebt ? 201 : 403, $debtStatus, "{$who}: debt repayment");
            $this->assertSame($mayPay ? 201 : 403, $payStatus, "{$who}: underpayment payment");
        }

        $this->assertSame(3, $debt->transactions()->count(), 'the Super Admin and the HR Manager recorded a repayment, and only the Super Admin wrote off');
        $this->assertSame(1, $owed->transactions()->count(), 'only the Super Admin recorded a payment');

        // Voiding the payment (a linked reversal): Super Admin only.
        $payment = $owed->transactions()->sole();
        $voidUrl = "/api/v1/admin/hr/payroll-corrections/{$owed->id}/transactions/{$payment->id}/void";

        foreach (array_keys(self::BASELINE) as $who) {
            $this->assertSame(403, $this->httpStatus($who, 'POST', $voidUrl, ['reason' => 'Matrix test void']), "{$who}: void of a payment");
        }

        $this->assertSame(401, $this->httpStatus(null, 'POST', $voidUrl, ['reason' => 'Matrix test void']));
        $this->assertSame(200, $this->httpStatus('super_admin', 'POST', $voidUrl, ['reason' => 'Matrix test void']));
        $this->assertSame(401, $this->httpStatus(null, 'POST', "/api/v1/admin/hr/payroll-corrections/{$debt->id}/transactions", $repayment + ['idempotency_key' => 'anon-key']));
    }

    public function test_voiding_a_payroll_payment_is_open_to_the_super_admin_and_the_hr_manager_only(): void
    {
        $run = PayrollRun::query()->create(['period' => '2026-09', 'status' => 'finalized']);
        $lines = [];

        // Two paid lines (a payroll line marked paid has a payment-ledger row linked to it).
        foreach ([1, 2] as $n) {
            $employee = $this->makeEmployee();
            $assignment = $this->makeAssignment($employee, $this->company, null, 300, '2026-01-01');
            $payment = EmployeePayment::query()->create(['employee_id' => $employee->id, 'payment_date' => '2026-10-01', 'amount' => '300.00', 'currency' => 'USD']);
            $item = PayrollItem::query()->create([
                'payroll_run_id' => $run->id, 'period' => '2026-09', 'employee_id' => $employee->id, 'work_assignment_id' => $assignment->id, 'employee_name' => 'Matrix '.$n,
                'employee_number' => $employee->employee_number, 'eligible_days' => 30, 'days_in_month' => 30, 'gross_cents' => 30000, 'net_cents' => 30000, 'calc_snapshot' => [],
                'payment_status' => 'paid', 'paid_at' => now(), 'employee_payment_id' => $payment->id,
            ]);
            $payment->update(['payroll_item_id' => $item->id]);
            $lines[$n] = $item;
        }

        $body = ['reason' => 'Matrix test void'];

        foreach (array_keys(self::BASELINE) as $who) {
            if ($who === 'hr_manager') {
                continue; // the HR Manager baseline is allowed; tested on its own line below
            }

            $this->assertSame(403, $this->httpStatus($who, 'POST', "/api/v1/admin/hr/payroll-items/{$lines[1]->id}/void-payment", $body), "{$who}: void of a payroll payment");
        }

        $this->assertSame(401, $this->httpStatus(null, 'POST', "/api/v1/admin/hr/payroll-items/{$lines[1]->id}/void-payment", $body));
        $this->assertSame('paid', $lines[1]->fresh()->payment_status, 'nothing was voided by a refused caller');

        $this->assertSame(200, $this->httpStatus('hr_manager', 'POST', "/api/v1/admin/hr/payroll-items/{$lines[1]->id}/void-payment", $body), 'the HR Manager may void');
        $this->assertSame(200, $this->httpStatus('super_admin', 'POST', "/api/v1/admin/hr/payroll-items/{$lines[2]->id}/void-payment", $body), 'the Super Admin may void');
        $this->assertSame(2, PayrollPaymentVoid::query()->count());
    }

    public function test_moving_an_existing_person_to_a_stage_is_for_the_super_admin_and_the_hr_manager_only(): void
    {
        $person = $this->makeEmployee('waiting');
        $person->update(['registration_origin' => 'existing']);
        $uri = "/api/v1/admin/hr/employees/{$person->id}/move-stage";
        $body = ['stage' => 'need_training', 'reason' => 'Matrix test: moved by the owner'];

        // Everybody else is refused - including a caller that holds hr.manage but is not an HR Manager (the role is checked on the server).
        foreach (array_keys(self::BASELINE) as $who) {
            if ($who === 'hr_manager') {
                continue;
            }

            $this->assertSame(403, $this->httpStatus($who, 'POST', $uri, $body), "{$who}: stage move");
        }

        $this->assertSame(401, $this->httpStatus(null, 'POST', $uri, $body));
        $this->assertSame('waiting', $person->fresh()->status->value, 'nothing was moved by a refused caller');

        $this->assertSame(200, $this->httpStatus('hr_manager', 'POST', $uri, $body), 'the HR Manager may move an existing person');
        $this->assertSame(200, $this->httpStatus('super_admin', 'POST', $uri, ['stage' => 'waiting', 'reason' => 'Matrix test: and back again']), 'the Super Admin may too');
        $this->assertSame('waiting', $person->fresh()->status->value);
    }

    public function test_hr_and_marketing_stay_separate_and_the_mobile_app_manager_reaches_neither(): void
    {
        $this->assertMatrix('GET', '/api/v1/admin/marketing/records', ['super_admin', 'marketing_manager']);
        $this->assertMatrix('GET', '/api/v1/admin/hr/payroll-runs', ['super_admin', 'hr_manager', 'payroll_viewer', 'payroll_manager_wrong_role']);
    }
}
