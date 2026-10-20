<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePayment;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollPaymentVoid;
use App\Models\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner rule: a payroll payment marked Paid can be voided by a Super Admin or an HR Manager, with a mandatory reason. The original payment is
 * never edited or deleted - a linked reversal is added - the role is checked on the server, a payment is voided once, and every total that
 * sums the payment ledger (dashboard, financial ledger, HR reports) and the corrections ledger reconcile.
 */
class PayrollPaymentVoidTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $company;

    private Employee $employee;

    private EmployeeWorkAssignment $assignment;

    private Admin $superAdmin;

    private string $super;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-12-20 10:00:00');
        $this->company = $this->makeCompany('Acme Co');
        $this->employee = $this->makeEmployee();
        $this->employee->update(['department_id' => Department::query()->create(['name' => 'Cleaning Dept'])->id]);
        $this->assignment = $this->makeAssignment($this->employee, $this->company, null, 300, '2026-01-01');
        [$this->superAdmin, $this->super] = $this->superAdminToken();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** @param list<string> $keys */
    private function tokenFor(AdminRole $role, array $keys): string
    {
        $admin = Admin::factory()->create(['role' => $role]);

        foreach ($keys as $key) {
            DB::table('admin_permissions')->insert(['admin_id' => $admin->id, 'permission_id' => Permission::query()->where('key', $key)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $admin->createToken('t')->plainTextToken;
    }

    private function hrManager(): string
    {
        return $this->tokenFor(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve']);
    }

    /** A finalized September line, marked paid by the Super Admin. */
    private function paidLine(): PayrollItem
    {
        $id = $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated()->json('data.id');
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/approve")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/finalize")->assertOk();
        $item = PayrollItem::query()->where('payroll_run_id', $id)->where('work_assignment_id', $this->assignment->id)->firstOrFail();
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/pay", [])->assertOk();

        return $item->refresh();
    }

    private function void(string $token, PayrollItem $item, array $body = ['reason' => 'Paid to the wrong account'])
    {
        return $this->as($token)->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/void-payment", $body);
    }

    private function ledgerCents(): int
    {
        return (int) round(((float) EmployeePayment::query()->where('employee_id', $this->employee->id)->sum('amount')) * 100);
    }

    public function test_only_a_super_admin_or_an_hr_manager_can_void_a_payroll_payment_and_a_reason_is_mandatory(): void
    {
        $item = $this->paidLine();
        $refused = [
            'a payroll approver who is not an HR Manager' => $this->tokenFor(AdminRole::Sales, ['hr.payroll.approve']),
            'an HR Manager without the approve permission' => $this->tokenFor(AdminRole::HrManager, ['hr.view', 'hr.payroll.view', 'hr.payroll.manage']),
            'a payroll viewer' => $this->tokenFor(AdminRole::Sales, ['hr.payroll.view']),
            'a Marketing Manager' => $this->tokenFor(AdminRole::MarketingManager, ['marketing.view']),
            'a Mobile App Manager' => $this->tokenFor(AdminRole::MobileAppManager, ['customers.view']),
        ];

        foreach ($refused as $who => $token) {
            $this->void($token, $item)->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/void-payment", ['reason' => 'Paid to the wrong account'])->assertUnauthorized();
        $this->assertSame('paid', $item->fresh()->payment_status, 'nothing changed');
        $this->assertSame(1, EmployeePayment::query()->count());

        // The reason is mandatory: missing, blank and too short are all refused, with nothing recorded.
        $hr = $this->hrManager();
        $this->void($hr, $item, [])->assertUnprocessable();
        $this->void($hr, $item, ['reason' => '   '])->assertUnprocessable();
        $this->void($hr, $item, ['reason' => 'no'])->assertUnprocessable();
        $this->assertSame('paid', $item->fresh()->payment_status);
        $this->assertSame(0, PayrollPaymentVoid::query()->count());

        // An HR Manager can; so can a Super Admin (on another payment, below).
        $this->void($hr, $item)->assertOk()->assertJsonPath('data.payment_status', 'unpaid');
    }

    public function test_the_original_payment_is_never_edited_a_linked_reversal_is_added_and_everything_is_audited(): void
    {
        $item = $this->paidLine();
        $original = EmployeePayment::query()->findOrFail($item->employee_payment_id);
        // The raw stored row, exactly as it is now.
        $rawBefore = (array) DB::table('employee_payments')->where('id', $original->id)->first();
        $hrToken = $this->hrManager();
        $hrAdmin = Admin::query()->where('role', AdminRole::HrManager->value)->latest('id')->firstOrFail();

        $this->void($hrToken, $item, ['reason' => 'Paid to the wrong account'])->assertOk();

        // The original row is byte-for-byte as it was (every column, including updated_at).
        $this->assertSame($rawBefore, (array) DB::table('employee_payments')->where('id', $original->id)->first());
        $original->refresh();
        $this->assertSame('300.00', (string) $original->amount);

        // A linked reversal: same amount, negative, pointing at the original; and the linked correction entry.
        $reversal = EmployeePayment::query()->where('reverses_payment_id', $original->id)->sole();
        $this->assertSame('-300.00', (string) $reversal->amount);
        $this->assertSame($this->employee->id, $reversal->employee_id);
        $this->assertNull($reversal->payroll_item_id);
        $this->assertSame($hrAdmin->id, $reversal->paid_by);
        $void = PayrollPaymentVoid::query()->sole();
        $this->assertSame([$item->id, $original->id, $reversal->id, 30000, 'Paid to the wrong account', $hrAdmin->id], [$void->payroll_item_id, $void->original_payment_id, $void->reversal_payment_id, (int) $void->amount_cents, $void->reason, $void->voided_by]);

        // The line is unpaid again; the payroll run is untouched.
        $item->refresh();
        $this->assertSame(['unpaid', null, null], [$item->payment_status, $item->paid_at, $item->employee_payment_id]);
        $this->assertSame('finalized', $item->run->status);
        $this->assertSame(30000, (int) $item->net_cents);

        // The audit event carries who, when, what and why.
        $log = AuditLog::query()->where('entity_type', PayrollItem::class)->where('entity_id', $item->id)->get()->first(fn ($l) => ($l->metadata['event'] ?? null) === 'payroll_payment_voided');
        $this->assertSame($hrAdmin->id, $log->admin_id);
        $this->assertNotNull($log->created_at);
        $this->assertSame([$this->employee->id, $original->id, $reversal->id, 30000, '2026-09', 'Paid to the wrong account'], [$log->metadata['employee_id'], $log->metadata['original_payment_id'], $log->metadata['reversal_payment_id'], $log->metadata['amount_cents'], $log->metadata['period'], $log->metadata['reason']]);
    }

    public function test_a_payment_is_voided_once_only_by_the_code_and_by_the_database(): void
    {
        $item = $this->paidLine();
        $original = EmployeePayment::query()->findOrFail($item->employee_payment_id);

        $this->void($this->super, $item)->assertOk();
        $this->void($this->super, $item, ['reason' => 'Clicking again by mistake'])->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_ITEM_NOT_PAID');
        $this->void($this->hrManager(), $item, ['reason' => 'A colleague voiding it again'])->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_ITEM_NOT_PAID');
        $this->assertSame(2, EmployeePayment::query()->count());
        $this->assertSame(1, PayrollPaymentVoid::query()->count());

        // Even if the code were bypassed, the database refuses a second reversal row, and a second void record, of the same payment.
        try {
            DB::transaction(fn () => EmployeePayment::query()->create(['employee_id' => $this->employee->id, 'reverses_payment_id' => $original->id, 'payment_date' => '2026-12-20', 'amount' => '-300.00', 'currency' => 'USD']));
            $this->fail('a second reversal of the same payment must be impossible');
        } catch (QueryException) {
            $this->assertSame(2, EmployeePayment::query()->count());
        }

        // A line that was never paid has nothing to void.
        $other = PayrollItem::query()->where('id', '!=', $item->id)->first();
        $this->assertNull($other);
        $unpaid = $item->replicate();
        $unpaid->forceFill(['work_assignment_id' => $this->makeAssignment($this->makeEmployee(), $this->company, null, 100, '2026-01-01')->id, 'employee_id' => $this->employee->id, 'payment_status' => 'unpaid', 'employee_payment_id' => null])->save();
        $this->void($this->super, $unpaid)->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_ITEM_NOT_PAID');
    }

    public function test_every_total_reconciles_after_the_reversal(): void
    {
        $item = $this->paidLine();
        $this->assertSame(30000, $this->ledgerCents());
        $viewer = $this->tokenFor(AdminRole::Sales, ['hr.view', 'hr.payroll.view', 'hr.reports.view']);
        $totals = fn () => collect($this->as($this->super)->getJson('/api/v1/admin/hr/reports/summary?from=2026-12-01&to=2026-12-31')->assertOk()->json('data.payment_totals_by_department'))->firstWhere('name', 'Cleaning Dept') ?? ['total_paid' => 'MISSING', 'total_payments' => -99];

        $this->assertSame([30000, 1], [(int) round(((float) $totals()['total_paid']) * 100), $totals()['total_payments']]);
        $this->assertSame('300.00', number_format((float) $this->as($this->super)->getJson('/api/v1/admin/hr/dashboard')->json('data.month_payments_total'), 2, '.', ''));

        $this->void($this->super, $item)->assertOk();

        // Payment ledger: original + reversal net to zero.
        $this->assertSame(0, $this->ledgerCents());
        $rows = collect($this->as($this->super)->getJson('/api/v1/admin/hr/reports/financial-ledger?type=payment')->assertOk()->json('data.data') ?? $this->as($this->super)->getJson('/api/v1/admin/hr/reports/financial-ledger?type=payment')->json('data'));
        $this->assertCount(2, $rows);
        $this->assertEquals(0.0, $rows->sum(fn ($r) => (float) $r['amount']));
        $this->assertSame([false, true], $rows->sortBy('id')->pluck('is_reversal')->values()->all());

        // Dashboard total and HR reports (amount and number of payments) agree: nothing paid.
        $this->assertSame('0.00', number_format((float) $this->as($this->super)->getJson('/api/v1/admin/hr/dashboard')->json('data.month_payments_total'), 2, '.', ''));
        $this->assertSame([0, 0], [(int) round(((float) $totals()['total_paid']) * 100), $totals()['total_payments']]);

        // Corrections ledger: the voided payroll payment is listed and totalled.
        $ledger = $this->as($viewer)->getJson('/api/v1/admin/hr/payroll-corrections')->assertOk()->json('data');
        $this->assertCount(1, $ledger['payroll_payment_voids']);
        $this->assertSame([30000, '2026-09', $this->employee->id], [$ledger['payroll_payment_voids'][0]['amount_cents'], $ledger['payroll_payment_voids'][0]['period'], $ledger['payroll_payment_voids'][0]['employee_id']]);
        $this->assertSame(30000, $ledger['totals']['payroll_payments_voided_cents']);

        // The payroll run, its totals and the unpaid status agree (the line shows as not paid again).
        $run = $this->as($this->super)->getJson('/api/v1/admin/hr/payroll-runs/'.$item->payroll_run_id)->assertOk()->json('data');
        $line = collect($run['items'])->firstWhere('id', $item->id);
        $this->assertSame('unpaid', $line['payment_status']);
        $this->assertCount(1, $line['payment_voids']);
        $this->assertSame('finalized', $run['status']);
    }

    public function test_the_line_can_be_paid_again_correctly_and_each_payment_is_voided_only_once(): void
    {
        $item = $this->paidLine();
        $first = EmployeePayment::query()->findOrFail($item->employee_payment_id);
        $this->void($this->super, $item)->assertOk();

        // Paid again: a new payment row (the first row keeps the unique link to the line), tied to the line through the line itself.
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/pay", [])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $item->refresh();
        $second = EmployeePayment::query()->findOrFail($item->employee_payment_id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNull($second->payroll_item_id);
        $this->assertStringContainsString("after payment #{$first->id} was voided", (string) $second->notes);
        $this->assertSame(30000, $this->ledgerCents(), '300.00 paid, voided, paid again = 300.00 net');

        // The second payment can be voided too (once), and the totals still reconcile.
        $this->void($this->hrManager(), $item, ['reason' => 'The employee was paid in cash instead'])->assertOk();
        $this->assertSame(0, $this->ledgerCents());
        $this->assertSame(2, PayrollPaymentVoid::query()->count());
        $this->assertSame(4, EmployeePayment::query()->count());
        $this->assertSame(2, EmployeePayment::query()->whereNotNull('reverses_payment_id')->count());
    }
}
