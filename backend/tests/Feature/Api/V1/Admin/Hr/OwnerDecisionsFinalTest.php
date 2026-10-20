<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeePayment;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Models\PayrollCorrectionTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Support\Hr\Payroll\PayrollPeriodView;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The owner's final decisions:
 *  2. a Super Admin can write off (part of) a debt, with a reason, an audit trail, no duplicates and no excess - the debt record stays;
 *  3. a Super Admin can void an underpayment payment: a linked reversal, the original untouched, totals reconcile, once only;
 *  4. a Temporary worker confirmed as a Replacement gets no payroll line, payroll for the month is refused until the record is corrected,
 *     nothing is converted silently, and the confirmation and the later correction are audited.
 */
class OwnerDecisionsFinalTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $company;

    private Admin $superAdmin;

    private string $super;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-12-20 10:00:00');
        $this->company = $this->makeCompany('Acme Co');
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

    private function debt(Employee $employee, int $cents): PayrollCorrection
    {
        $run = PayrollRun::query()->firstOrCreate(['period' => '2026-09'], ['status' => 'finalized']);

        return PayrollCorrection::query()->create([
            'batch_key' => (string) Str::uuid(), 'employee_id' => $employee->id, 'payroll_run_id' => $run->id, 'period' => '2026-09',
            'kind' => 'overpayment', 'action' => 'edited', 'recorded_net_cents' => $cents, 'corrected_net_cents' => 0, 'difference_cents' => $cents,
            'outstanding_cents' => $cents, 'status' => 'open', 'reason' => 'earlier overpayment', 'corrected_by' => $this->superAdmin->id,
        ]);
    }

    private function owedToEmployee(Employee $employee, int $cents): PayrollCorrection
    {
        $run = PayrollRun::query()->firstOrCreate(['period' => '2026-09'], ['status' => 'finalized']);

        return PayrollCorrection::query()->create([
            'batch_key' => (string) Str::uuid(), 'employee_id' => $employee->id, 'payroll_run_id' => $run->id, 'period' => '2026-09',
            'kind' => 'underpayment', 'action' => 'edited', 'recorded_net_cents' => 0, 'corrected_net_cents' => $cents, 'difference_cents' => -$cents,
            'outstanding_cents' => $cents, 'status' => 'open', 'reason' => 'earlier underpayment', 'corrected_by' => $this->superAdmin->id,
        ]);
    }

    private function transact(string $token, PayrollCorrection $correction, array $body)
    {
        return $this->as($token)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions", array_replace([
            'type' => 'debt_repayment', 'amount' => 100, 'transaction_date' => '2026-12-01', 'idempotency_key' => (string) Str::uuid(),
        ], $body));
    }

    private function void(string $token, PayrollCorrection $correction, PayrollCorrectionTransaction $transaction, string $reason = 'Recorded for the wrong person')
    {
        return $this->as($token)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$transaction->id}/void", ['reason' => $reason]);
    }

    /** @return list<array<string, mixed>> audit metadata of a correction, in order */
    private function events(PayrollCorrection $correction): array
    {
        return AuditLog::query()->where('entity_type', PayrollCorrection::class)->where('entity_id', $correction->id)->orderBy('id')->get()->map(fn ($l) => [...($l->metadata ?? []), '_admin' => $l->admin_id, '_at' => $l->created_at])->all();
    }

    // ------------------------------------------------------------------ 2. debt write-off

    public function test_only_a_super_admin_writes_a_debt_off_with_a_reason_and_every_step_is_audited(): void
    {
        $employee = $this->makeEmployee('inactive');
        $debt = $this->debt($employee, 50000);
        $hr = $this->hrManager();

        // The HR Manager manages repayments but may NOT write off; neither may anybody else.
        $this->transact($hr, $debt, ['type' => 'debt_repayment', 'amount' => 100, 'idempotency_key' => 'repay-key-1'])->assertCreated();
        $this->transact($hr, $debt, ['type' => 'debt_write_off', 'amount' => 50, 'reason' => 'Employee cannot repay'])->assertForbidden()->assertJsonPath('error_code', 'PAYROLL_CORRECTION_NOT_ALLOWED');
        $this->transact($this->tokenFor(AdminRole::Sales, ['hr.payroll.manage']), $debt, ['type' => 'debt_write_off', 'amount' => 50, 'reason' => 'Employee cannot repay'])->assertForbidden();
        $this->transact($this->tokenFor(AdminRole::MarketingManager, ['marketing.view']), $debt, ['type' => 'debt_write_off', 'amount' => 50, 'reason' => 'Employee cannot repay'])->assertForbidden();
        $this->assertSame(1, PayrollCorrectionTransaction::query()->count(), 'nothing was written off by an unauthorized caller');

        // A reason is mandatory: missing, blank and too short are all refused.
        $this->transact($this->super, $debt, ['type' => 'debt_write_off', 'amount' => 50])->assertUnprocessable();
        $this->transact($this->super, $debt, ['type' => 'debt_write_off', 'amount' => 50, 'reason' => '   '])->assertUnprocessable();
        $this->transact($this->super, $debt, ['type' => 'debt_write_off', 'amount' => 50, 'reason' => 'no'])->assertUnprocessable();
        $this->assertSame(40000 + 0, (int) $debt->fresh()->outstanding_cents, 'the debt is unchanged: 500.00 - 100.00 repaid');

        // More than what is owed is refused.
        $this->transact($this->super, $debt, ['type' => 'debt_write_off', 'amount' => 400.01, 'reason' => 'Employee cannot repay'])->assertUnprocessable()->assertJsonPath('error_code', 'AMOUNT_EXCEEDS_OUTSTANDING');

        // A partial write-off; a retry with the same key adds nothing; the same key for another amount is refused.
        $key = (string) Str::uuid();
        $this->transact($this->super, $debt, ['type' => 'debt_write_off', 'amount' => 150, 'reason' => 'Employee left the country', 'idempotency_key' => $key])->assertCreated()->assertJsonPath('data.outstanding_cents', 25000);
        $this->transact($this->super, $debt, ['type' => 'debt_write_off', 'amount' => 150, 'reason' => 'Employee left the country', 'idempotency_key' => $key])->assertOk()->assertJsonPath('data.replayed', true);
        $this->transact($this->super, $debt, ['type' => 'debt_write_off', 'amount' => 90, 'reason' => 'Employee left the country', 'idempotency_key' => $key])->assertStatus(409);
        $this->assertSame(2, PayrollCorrectionTransaction::query()->count());
        $this->assertSame('open', $debt->fresh()->status, 'a partial write-off leaves the rest as a debt');

        // The rest is written off: the debt is cleared and marked as written off - the record, the repayment and the write-offs stay.
        $this->transact($this->super, $debt, ['type' => 'debt_write_off', 'amount' => 250, 'reason' => 'Remaining balance forgiven by the owner'])->assertCreated()->assertJsonPath('data.outstanding_cents', 0);
        $debt->refresh();
        $this->assertSame('written_off', $debt->status);
        $this->assertSame(50000, (int) $debt->difference_cents, 'the original debt amount is never edited');
        $this->transact($this->super, $debt, ['type' => 'debt_write_off', 'amount' => 1, 'reason' => 'Writing off a debt that is already cleared'])->assertUnprocessable()->assertJsonPath('error_code', 'AMOUNT_EXCEEDS_OUTSTANDING');

        // The ledger reads line by line and reconciles: debt = repaid + written off + still owed.
        $rows = $debt->transactions()->get();
        $this->assertSame(['debt_repayment', 'debt_write_off', 'debt_write_off'], $rows->pluck('type')->all());
        $this->assertSame([40000, 25000], [(int) $rows[1]->balance_before_cents, (int) $rows[1]->balance_after_cents]);
        $this->assertSame([25000, 0], [(int) $rows[2]->balance_before_cents, (int) $rows[2]->balance_after_cents]);
        $this->assertSame('Employee left the country', $rows[1]->reason);
        $this->assertSame($this->superAdmin->id, $rows[1]->recorded_by);
        $this->assertSame(50000, $rows->where('type', 'debt_repayment')->sum('amount_cents') + $rows->where('type', 'debt_write_off')->sum('amount_cents') + (int) $debt->outstanding_cents);

        // The audit trail has everything asked for.
        $written = collect($this->events($debt))->where('event', 'debt_written_off')->values();
        $this->assertCount(2, $written);
        $first = $written[0];
        $this->assertSame($this->superAdmin->id, $first['_admin'], 'actor');
        $this->assertNotNull($first['_at'], 'timestamp');
        $this->assertSame($employee->id, $first['employee_id'], 'employee');
        $this->assertSame($debt->id, $first['debt_reference'], 'debt reference');
        $this->assertSame(15000, $first['amount_cents'], 'amount written off');
        $this->assertSame(40000, $first['outstanding_before_cents'], 'previous outstanding balance');
        $this->assertSame(25000, $first['outstanding_after_cents'], 'remaining balance');
        $this->assertSame('Employee left the country', $first['reason'], 'reason');

        // A write-off cannot be voided (it is not silently undone), and the debt record is still there.
        $this->void($this->super, $debt, $rows[1])->assertUnprocessable()->assertJsonPath('error_code', 'TRANSACTION_NOT_VOIDABLE');
        $this->assertNotNull(PayrollCorrection::query()->find($debt->id));
    }

    public function test_a_written_off_amount_is_never_deducted_from_salary_again(): void
    {
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->company, null, 200, '2026-01-01');
        $debt = $this->debt($employee, 50000);

        $this->transact($this->super, $debt, ['type' => 'debt_write_off', 'amount' => 400, 'reason' => 'Forgiven by the owner'])->assertCreated();

        $id = $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertCreated()->json('data.id');
        $line = PayrollItem::query()->where('payroll_run_id', $id)->where('work_assignment_id', $assignment->id)->firstOrFail();

        $this->assertSame(10000, (int) $line->other_deduction_cents, 'only the 100.00 still owed is taken, not the 400.00 that was written off');
        $this->assertSame(10000, (int) $line->net_cents);
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/approve")->assertOk();
        $this->assertSame(0, (int) $debt->fresh()->outstanding_cents);
        $this->assertSame('written_off', $debt->fresh()->status);
    }

    // ------------------------------------------------------------------ 3. void an employee payment

    public function test_a_super_admin_voids_an_underpayment_payment_with_a_linked_reversal_and_the_totals_reconcile(): void
    {
        $employee = $this->makeEmployee();
        $owed = $this->owedToEmployee($employee, 15000);

        $this->transact($this->super, $owed, ['type' => 'underpayment_payment', 'amount' => 100, 'idempotency_key' => 'pay-key-1', 'transaction_date' => '2026-12-18'])->assertCreated();
        $payment = PayrollCorrectionTransaction::query()->where('type', 'underpayment_payment')->sole();
        $original = EmployeePayment::query()->findOrFail($payment->employee_payment_id);
        $this->assertSame(5000, (int) $owed->fresh()->outstanding_cents);

        // Only the Super Admin, only with a reason.
        $this->void($this->hrManager(), $owed, $payment)->assertForbidden()->assertJsonPath('error_code', 'PAYROLL_CORRECTION_NOT_ALLOWED');
        $this->void($this->tokenFor(AdminRole::Sales, ['hr.payroll.manage']), $owed, $payment)->assertForbidden();
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-corrections/{$owed->id}/transactions/{$payment->id}/void", [])->assertUnprocessable();
        $this->void($this->super, $owed, $payment, 'x')->assertUnprocessable();
        $this->assertSame(0, PayrollCorrectionTransaction::query()->where('type', 'underpayment_payment_reversal')->count());
        $this->assertSame(1, EmployeePayment::query()->count());

        $this->void($this->super, $owed, $payment, 'Paid to the wrong employee')->assertOk()->assertJsonPath('data.outstanding_cents', 15000);

        // The original transaction and payment row are untouched; a linked reversal exists in both ledgers.
        $payment->refresh();
        $this->assertSame(10000, (int) $payment->amount_cents);
        $this->assertNull($payment->voided_at, 'the original is not edited');
        $this->assertSame('100.00', (string) $original->fresh()->amount);
        $reversalTx = PayrollCorrectionTransaction::query()->where('type', 'underpayment_payment_reversal')->sole();
        $this->assertSame($payment->id, $reversalTx->reverses_transaction_id);
        $this->assertSame(10000, (int) $reversalTx->amount_cents);
        $this->assertSame([5000, 15000], [(int) $reversalTx->balance_before_cents, (int) $reversalTx->balance_after_cents]);
        $this->assertSame('Paid to the wrong employee', $reversalTx->reason);
        $reversalPayment = EmployeePayment::query()->findOrFail($reversalTx->employee_payment_id);
        $this->assertSame($original->id, $reversalPayment->reverses_payment_id);
        $this->assertSame('-100.00', (string) $reversalPayment->amount);

        // Totals reconcile: the payment ledger nets to zero, the amount owed is back, the dashboard and the ledger report agree.
        $this->assertSame(0, (int) round(((float) EmployeePayment::query()->where('employee_id', $employee->id)->sum('amount')) * 100));
        $this->assertSame('0.00', number_format((float) $this->as($this->super)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data.month_payments_total'), 2, '.', ''));
        $ledger = $this->as($this->super)->getJson('/api/v1/admin/hr/reports/financial-ledger?type=payment')->assertOk()->json('data');
        $rows = collect($ledger['data'] ?? $ledger);
        $this->assertSame(2, $rows->count());
        $this->assertEquals(0.0, $rows->sum(fn ($r) => (float) $r['amount']));
        $this->assertSame([false, true], $rows->sortBy('id')->pluck('is_reversal')->values()->all());
        $row = collect($this->as($this->tokenFor(AdminRole::Sales, ['hr.payroll.view']))->getJson('/api/v1/admin/hr/payroll-corrections')->json('data.corrections'))->firstWhere('id', $owed->id);
        $this->assertSame(0, $row['paid_cents']);
        $this->assertSame(15000, $row['outstanding_cents']);
        $this->assertTrue(collect($row['transactions'])->firstWhere('id', $payment->id)['voided']);

        // Once only: a second void is refused, and the database refuses a second reversal row even if the code were bypassed.
        $this->void($this->super, $owed, $payment, 'Trying again')->assertUnprocessable()->assertJsonPath('error_code', 'TRANSACTION_ALREADY_VOIDED');
        $this->assertSame(2, EmployeePayment::query()->count());
        try {
            EmployeePayment::query()->create(['employee_id' => $employee->id, 'reverses_payment_id' => $original->id, 'payment_date' => '2026-12-20', 'amount' => '-100.00', 'currency' => 'USD']);
            $this->fail('a second reversal of the same payment must be impossible');
        } catch (QueryException) {
            $this->assertSame(2, EmployeePayment::query()->count());
        }
        // A reversal itself cannot be voided.
        $this->void($this->super, $owed, $reversalTx)->assertUnprocessable()->assertJsonPath('error_code', 'TRANSACTION_NOT_VOIDABLE');

        // The employee can be paid again, correctly: 100.00 voided, then 150.00 paid = the whole underpayment.
        $this->transact($this->super, $owed, ['type' => 'underpayment_payment', 'amount' => 150, 'transaction_date' => '2026-12-19'])->assertCreated()->assertJsonPath('data.outstanding_cents', 0);
        $this->assertSame('settled', $owed->fresh()->status);
        $this->assertSame(15000, (int) round(((float) EmployeePayment::query()->where('employee_id', $employee->id)->sum('amount')) * 100));

        // The audit record of the void.
        $void = collect($this->events($owed))->firstWhere('event', 'underpayment_payment_voided');
        $this->assertSame($this->superAdmin->id, $void['_admin']);
        $this->assertNotNull($void['_at']);
        $this->assertSame($payment->id, $void['original_transaction_id']);
        $this->assertSame($reversalTx->id, $void['reversal_transaction_id']);
        $this->assertSame($original->id, $void['original_employee_payment_id']);
        $this->assertSame($employee->id, $void['employee_id']);
        $this->assertSame(10000, $void['amount_cents']);
        $this->assertSame([5000, 15000], [$void['owed_before_cents'], $void['owed_after_cents']]);
        $this->assertSame(0, $void['employee_payment_ledger_net_cents']);
        $this->assertSame('Paid to the wrong employee', $void['reason']);
    }

    // ------------------------------------------------------------------ 4. Temporary confirmed as a Replacement

    private function confirmedTemporaryReplacement(): array
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $temp = $this->makeEmployee('active', 'temporary');

        $this->as($this->super)->postJson("/api/v1/admin/hr/client-companies/{$this->company->id}/employees/{$original->id}/replace", [
            'replacement_employee_id' => $temp->id, 'replacement_date' => '2026-09-16', 'assignment_type' => 'replacement',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'TEMPORARY_REGISTERED_AS_REPLACEMENT');

        $id = $this->as($this->super)->postJson("/api/v1/admin/hr/client-companies/{$this->company->id}/employees/{$original->id}/replace", [
            'replacement_employee_id' => $temp->id, 'replacement_date' => '2026-09-16', 'assignment_type' => 'replacement', 'acknowledge_temporary_as_replacement' => true,
        ])->assertOk()->json('data.replacement.id');

        return [$original->refresh(), $temp, EmployeeWorkAssignment::query()->findOrFail($id)];
    }

    public function test_a_temporary_confirmed_as_replacement_gets_no_payroll_line_and_the_month_is_refused_until_it_is_corrected(): void
    {
        [$original, $temp, $replacement] = $this->confirmedTemporaryReplacement();

        // The confirmation is kept; nothing was converted.
        $this->assertSame('replacement', $replacement->assignment_type->value);
        $this->assertSame('temporary', $temp->fresh()->employment_type);
        $this->assertNotNull(AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $replacement->id)->get()->first(fn ($l) => isset($l->metadata['temporary_registered_as_replacement_confirmed'])));

        // Payroll for the month cannot be calculated: no run, no lines, a clear list of what to correct.
        $response = $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_UNRESOLVED_TEMPORARY_REPLACEMENT');
        $this->assertSame($replacement->id, $response->json('errors.assignments.0.assignment_id'));
        $this->assertStringContainsString($temp->employee_number, $response->json('message'));
        $this->assertSame(0, PayrollRun::query()->count());
        $this->assertSame(0, PayrollItem::query()->count());

        // The live estimate creates no line for these days and says why; the company screen flags the record.
        $view = (new PayrollPeriodView)->forPeriod('2026-09');
        $this->assertNotContains($replacement->id, array_column($view['lines'], 'work_assignment_id'));
        $this->assertTrue(collect($view['warnings'])->contains(fn ($w) => str_contains($w, 'UNRESOLVED')));
        $row = collect($this->as($this->super)->getJson("/api/v1/admin/hr/client-companies/{$this->company->id}/employees?period=2026-09")->json('data.employees'))->firstWhere('assignment_id', $replacement->id);
        $this->assertTrue($row['needs_correction']);
        $this->assertNull($row['payroll']['calculated']);
        $this->assertStringContainsString('Temporary', $row['payroll']['unavailable_reason']);

        // A month the record does not touch is not blocked.
        $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-08'])->assertCreated();
    }

    public function test_correcting_the_employee_type_unblocks_payroll_pays_the_days_and_audits_who_corrected_it(): void
    {
        [$original, $temp, $replacement] = $this->confirmedTemporaryReplacement();
        $hr = $this->hrManager();

        $this->as($hr)->putJson("/api/v1/admin/hr/employees/{$temp->id}", ['employment_type' => 'permanent'])->assertOk();

        $this->assertSame('permanent', $temp->fresh()->employment_type);
        $this->assertSame('replacement', $replacement->fresh()->assignment_type->value, 'the assignment type was not touched: only the employee was corrected, explicitly');

        $resolved = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $replacement->id)->get()->first(fn ($l) => ($l->metadata['event'] ?? null) === 'temporary_registered_as_replacement_resolved');
        $this->assertNotNull($resolved, 'the correction is audited on the assignment');
        $this->assertSame('employee_type_changed', $resolved->metadata['method']);
        $this->assertSame('temporary', $resolved->metadata['employment_type_from']);
        $this->assertSame('permanent', $resolved->metadata['employment_type_to']);
        $this->assertNotNull($resolved->admin_id);
        $employeeLog = AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $temp->id)->latest('id')->firstOrFail();
        $this->assertSame(['from' => 'temporary', 'to' => 'permanent'], $employeeLog->metadata['employment_type']);

        $id = $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated()->json('data.id');
        $line = PayrollItem::query()->where('payroll_run_id', $id)->where('work_assignment_id', $replacement->id)->firstOrFail();
        $this->assertSame(15000, (int) $line->gross_cents, '15 days of the inherited 300.00 salary: paid only after the correction');
    }

    public function test_cancelling_the_unresolved_replacement_is_another_audited_way_to_correct_it(): void
    {
        [, , $replacement] = $this->confirmedTemporaryReplacement();

        $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertUnprocessable();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/work-assignments/{$replacement->id}/cancel", ['reason' => 'Registered by mistake: this was Temporary cover'])->assertOk();

        $resolved = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $replacement->id)->get()->first(fn ($l) => ($l->metadata['event'] ?? null) === 'temporary_registered_as_replacement_resolved');
        $this->assertSame('replacement_cancelled', $resolved->metadata['method']);
        $this->assertSame('Registered by mistake: this was Temporary cover', $resolved->metadata['reason']);
        $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated();
    }

    public function test_an_assignment_edited_from_replacement_back_to_permanent_is_audited_and_never_converted_silently(): void
    {
        [, $temp, $replacement] = $this->confirmedTemporaryReplacement();

        // Nothing converts it by itself: the type stays until somebody changes it, explicitly.
        $this->assertSame('replacement', $replacement->fresh()->assignment_type->value);

        $this->as($this->super)->putJson("/api/v1/admin/hr/work-assignments/{$replacement->id}", ['assignment_type' => 'permanent'])->assertOk();

        $resolved = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $replacement->id)->get()->first(fn ($l) => ($l->metadata['event'] ?? null) === 'temporary_registered_as_replacement_resolved');
        $this->assertSame('assignment_type_changed', $resolved->metadata['method']);
        $this->assertSame('temporary', $temp->fresh()->employment_type, "the employee's own type is not changed by editing the assignment");
    }
}
