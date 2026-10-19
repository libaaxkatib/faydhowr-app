<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeePayment;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Models\PayrollCorrectionTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollRecoveryDeduction;
use App\Models\PayrollRun;
use App\Models\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decisions of the RC6 update:
 *  1. an underpayment is paid ONLY by a Super Admin, as its own traceable transaction (the closed month is not rewritten);
 *  2. a leaving employee's debt is taken from the final salary up to what is available, the rest stays a debt that a Super Admin or an
 *     HR Manager manages (audited, server-authorised, never duplicated);
 *  3. a Permanent's pay is the full monthly salary; only recorded Absent marks reduce it;
 *  4. a Temporary worker registered as a Replacement needs an explicit, recorded HR confirmation;
 *  5. when the Permanent's salary changes, the Replacement gets the new salary for the whole month of the change.
 */
class OwnerDecisionsUpdateTest extends TestCase
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

    private function generate(string $period): PayrollRun
    {
        $id = $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated()->json('data.id');

        return PayrollRun::query()->findOrFail($id);
    }

    private function close(string $period, bool $finalize = true): PayrollRun
    {
        $run = $this->generate($period);
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/approve")->assertOk();

        if ($finalize) {
            $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/finalize")->assertOk();
        }

        return $run->refresh();
    }

    private function line(string $period, EmployeeWorkAssignment $assignment): PayrollItem
    {
        return PayrollItem::query()->where('period', $period)->where('work_assignment_id', $assignment->id)->firstOrFail();
    }

    private function companyUrl(string $tail): string
    {
        return "/api/v1/admin/hr/client-companies/{$this->company->id}/employees".$tail;
    }

    private function replace(EmployeeWorkAssignment $original, Employee $replacement, array $over = [])
    {
        return $this->as($this->super)->postJson($this->companyUrl("/{$original->id}/replace"), array_replace([
            'replacement_employee_id' => $replacement->id, 'replacement_date' => '2026-09-16', 'assignment_type' => 'replacement',
        ], $over));
    }

    private function owed(Employee $employee, int $cents, string $period = '2026-09'): PayrollCorrection
    {
        $run = PayrollRun::query()->firstOrCreate(['period' => $period], ['status' => 'finalized']);

        return PayrollCorrection::query()->create([
            'batch_key' => (string) Str::uuid(), 'employee_id' => $employee->id, 'payroll_run_id' => $run->id, 'period' => $period,
            'kind' => 'overpayment', 'action' => 'edited', 'recorded_net_cents' => $cents, 'corrected_net_cents' => 0, 'difference_cents' => $cents,
            'outstanding_cents' => $cents, 'status' => 'open', 'reason' => 'earlier overpayment', 'corrected_by' => $this->superAdmin->id,
        ]);
    }

    private function transact(string $token, PayrollCorrection $correction, array $body)
    {
        return $this->as($token)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions", array_replace([
            'type' => 'debt_repayment', 'amount' => 100, 'transaction_date' => '2026-12-01', 'idempotency_key' => (string) Str::uuid(),
        ], $body));
    }

    // ------------------------------------------------------------------ 1. underpayments

    public function test_an_underpayment_is_owed_separately_and_only_a_super_admin_pays_it_as_a_traceable_transaction(): void
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $standIn = $this->makeEmployee();
        $run = $this->close('2026-09');
        $itemsBefore = PayrollItem::query()->where('payroll_run_id', $run->id)->get(['id', 'net_cents', 'gross_cents'])->toArray();
        $this->replace($original, $standIn, ['correction_reason' => 'The replacement started on 16 September'])->assertOk();

        $under = PayrollCorrection::query()->where('kind', 'underpayment')->sole();
        $this->assertSame(15000, (int) $under->outstanding_cents, 'the amount owed is recorded on its own');
        $this->assertSame('open', $under->status);

        // Nobody but a Super Admin can pay it, including an HR Manager who may manage debts.
        $this->transact($this->hrManager(), $under, ['type' => 'underpayment_payment', 'amount' => 100])->assertForbidden()->assertJsonPath('error_code', 'PAYROLL_CORRECTION_NOT_ALLOWED');
        $this->assertSame(0, EmployeePayment::query()->count());

        // The Super Admin pays in two steps; each is a separate transaction and a payment-ledger entry.
        $key = (string) Str::uuid();
        $first = $this->transact($this->super, $under, ['type' => 'underpayment_payment', 'amount' => 100, 'idempotency_key' => $key, 'note' => 'Cash, first part'])->assertCreated();
        $this->assertSame(5000, $first->json('data.outstanding_cents'));
        $payment = EmployeePayment::query()->sole();
        $this->assertSame($standIn->id, $payment->employee_id);
        $this->assertSame('100.00', (string) $payment->amount);
        $this->assertNull($payment->payroll_item_id);
        $this->assertStringContainsString("correction #{$under->id}", (string) $payment->notes);
        $this->assertSame($this->superAdmin->id, $payment->paid_by);

        // A retry of the same request changes nothing.
        $this->transact($this->super, $under, ['type' => 'underpayment_payment', 'amount' => 100, 'idempotency_key' => $key])->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, EmployeePayment::query()->count());
        $this->assertSame(1, PayrollCorrectionTransaction::query()->count());
        $this->transact($this->super, $under, ['type' => 'underpayment_payment', 'amount' => 70, 'idempotency_key' => $key])->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REUSED');

        // More than what is owed, the wrong kind and an invalid amount are refused.
        $this->transact($this->super, $under, ['type' => 'underpayment_payment', 'amount' => 51])->assertUnprocessable()->assertJsonPath('error_code', 'AMOUNT_EXCEEDS_OUTSTANDING');
        $this->transact($this->super, $under, ['type' => 'debt_repayment', 'amount' => 10])->assertUnprocessable()->assertJsonPath('error_code', 'CORRECTION_WRONG_KIND');
        $this->transact($this->super, $under, ['type' => 'underpayment_payment', 'amount' => 0])->assertUnprocessable();

        $last = $this->transact($this->super, $under, ['type' => 'underpayment_payment', 'amount' => 50])->assertCreated();
        $this->assertSame(0, $last->json('data.outstanding_cents'));
        $this->assertSame('settled', $under->fresh()->status);
        $this->assertSame(15000, (int) EmployeePayment::query()->sum(DB::raw('amount * 100')));

        // The closed month was never rewritten, and every step is in the audit history.
        $this->assertSame($itemsBefore, PayrollItem::query()->where('payroll_run_id', $run->id)->get(['id', 'net_cents', 'gross_cents'])->toArray());
        $this->assertSame('finalized', $run->fresh()->status);
        $events = AuditLog::query()->where('entity_type', PayrollCorrection::class)->where('entity_id', $under->id)->get()->pluck('metadata.event')->filter()->values()->all();
        $this->assertSame(['payroll_corrected', 'underpayment_paid', 'underpayment_paid'], $events);

        // A payment already written to the payment ledger cannot be voided away.
        $transaction = PayrollCorrectionTransaction::query()->firstOrFail();
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-corrections/{$under->id}/transactions/{$transaction->id}/void", ['reason' => 'Trying to undo a payment'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'TRANSACTION_NOT_VOIDABLE');
    }

    // ------------------------------------------------------------------ 2. a leaving employee's debt

    public function test_a_leaving_employees_final_salary_pays_back_what_it_can_and_the_rest_stays_a_debt_that_is_not_deducted_twice(): void
    {
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->company, null, 200, '2026-01-01');
        $assignment->update(['status' => 'ended', 'end_date' => '2026-10-15']);
        $employee->update(['status' => 'inactive']);
        $correction = $this->owed($employee, 50000);

        $run = $this->close('2026-10', false);
        $line = $this->line('2026-10', $assignment);

        $this->assertSame(9677, (int) $line->gross_cents, 'final salary: 15 of 31 days of 200.00');
        $this->assertSame(9677, (int) $line->other_deduction_cents, 'taken up to what is available');
        $this->assertSame(0, (int) $line->net_cents, 'never more than the final payable salary');
        $this->assertSame(40323, (int) $correction->fresh()->outstanding_cents, 'the rest carries forward as an outstanding debt');
        $this->assertTrue(collect($run->fresh()->totals['warnings'])->contains(fn ($w) => str_contains($w, 'outstanding debt')));

        // Later months have no salary to take from and never deduct again.
        $this->generate('2026-11');
        $this->assertSame(0, PayrollRecoveryDeduction::query()->where('period', '2026-11')->count());
        $this->assertSame(40323, (int) $correction->fresh()->outstanding_cents);
        $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertUnprocessable(); // an approved month cannot be regenerated: nothing is stacked
        $this->assertSame(1, PayrollRecoveryDeduction::query()->where('payroll_correction_id', $correction->id)->count());

        // The ledger flags it for follow-up.
        $row = $this->as($this->tokenFor(AdminRole::Sales, ['hr.payroll.view']))->getJson('/api/v1/admin/hr/payroll-corrections')->assertOk()->json('data.corrections.0');
        $this->assertTrue($row['needs_follow_up']);
        $this->assertSame('inactive', $row['employee_status']);
        $this->assertSame(40323, $row['outstanding_cents']);
    }

    public function test_super_admin_and_hr_manager_manage_the_remaining_debt_everyone_else_is_refused_and_nothing_is_recorded_twice(): void
    {
        $employee = $this->makeEmployee('inactive');
        $correction = $this->owed($employee, 40323);
        $hr = $this->hrManager();

        // Server-side authorization: other roles and permission-less users cannot, even with payroll permissions.
        foreach ([
            'a payroll viewer' => $this->tokenFor(AdminRole::Sales, ['hr.payroll.view']),
            'a role without the HR Manager role but with payroll manage' => $this->tokenFor(AdminRole::Sales, ['hr.payroll.manage']),
            'a Marketing Manager' => $this->tokenFor(AdminRole::MarketingManager, ['marketing.view']),
            'a Mobile App Manager' => $this->tokenFor(AdminRole::MobileAppManager, ['customers.view']),
        ] as $who => $token) {
            $this->assertContains($this->transact($token, $correction, ['amount' => 10])->getStatusCode(), [403], "{$who} must be refused");
        }

        $this->assertSame(0, PayrollCorrectionTransaction::query()->count());

        // The HR Manager records a repayment; a retry with the same key adds nothing.
        $key = (string) Str::uuid();
        $this->transact($hr, $correction, ['amount' => 100, 'idempotency_key' => $key, 'note' => 'Paid in cash at the office'])->assertCreated()->assertJsonPath('data.outstanding_cents', 30323);
        $this->transact($hr, $correction, ['amount' => 100, 'idempotency_key' => $key])->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, PayrollCorrectionTransaction::query()->count());
        $this->assertSame(30323, (int) $correction->fresh()->outstanding_cents);
        $this->assertSame(0, EmployeePayment::query()->count(), 'a repayment received is not a payment out');

        // The Super Admin records more; nobody can repay more than what is owed.
        $this->transact($this->super, $correction, ['amount' => 303.24])->assertUnprocessable()->assertJsonPath('error_code', 'AMOUNT_EXCEEDS_OUTSTANDING');
        $this->transact($this->super, $correction, ['amount' => 303.23])->assertCreated()->assertJsonPath('data.outstanding_cents', 0);
        $this->assertSame('recovered', $correction->fresh()->status);
        $this->transact($this->super, $correction, ['amount' => 1])->assertUnprocessable();

        // A repayment recorded by mistake is voided (kept, with who and why) and the debt is restored; it cannot be voided twice.
        $mistake = PayrollCorrectionTransaction::query()->where('amount_cents', 30323)->firstOrFail();
        $this->as($this->tokenFor(AdminRole::Sales, ['hr.payroll.manage']))->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$mistake->id}/void", ['reason' => 'Recorded for the wrong person'])->assertForbidden();
        $this->as($hr)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$mistake->id}/void", ['reason' => 'x'])->assertUnprocessable();
        $this->as($hr)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$mistake->id}/void", ['reason' => 'Recorded for the wrong person'])->assertOk()->assertJsonPath('data.outstanding_cents', 30323);
        $this->as($hr)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$mistake->id}/void", ['reason' => 'Recorded for the wrong person'])->assertUnprocessable()->assertJsonPath('error_code', 'TRANSACTION_ALREADY_VOIDED');

        $mistake->refresh();
        $this->assertNotNull($mistake->voided_at);
        $this->assertSame('Recorded for the wrong person', $mistake->void_reason);
        $this->assertSame(2, PayrollCorrectionTransaction::query()->count(), 'the voided repayment is kept');

        // Every action is audited with its actor.
        $events = AuditLog::query()->where('entity_type', PayrollCorrection::class)->where('entity_id', $correction->id)->orderBy('id')->get();
        $this->assertSame(['debt_repayment_recorded', 'debt_repayment_recorded', 'debt_repayment_voided'], $events->pluck('metadata.event')->all());
        $this->assertSame(30323, $events->last()->metadata['outstanding_after_cents']);
        $this->assertSame($events->pluck('admin_id')->filter()->count(), 3);
    }

    public function test_a_repayment_recorded_outside_payroll_reduces_what_payroll_still_takes_and_cannot_overlap_a_scheduled_deduction(): void
    {
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->company, null, 200, '2026-01-01');
        $correction = $this->owed($employee, 50000);
        $hr = $this->hrManager();

        // A draft month already schedules 200.00 of the 500.00: only 300.00 can be repaid by hand.
        $this->generate('2026-10');
        $this->transact($hr, $correction, ['amount' => 300.01])->assertUnprocessable()->assertJsonPath('error_code', 'AMOUNT_EXCEEDS_OUTSTANDING');
        $this->transact($hr, $correction, ['amount' => 300])->assertCreated();

        // Regenerating the draft now takes nothing more than what is still owed (0.00): never a duplicate.
        $this->generate('2026-10');
        $this->assertSame(20000, (int) $this->line('2026-10', $assignment)->other_deduction_cents, 'the draft deducts the remaining 200.00 only once');
        $this->assertSame(20000, (int) PayrollRecoveryDeduction::query()->where('payroll_correction_id', $correction->id)->sum('amount_cents'));
        $run = PayrollRun::query()->where('period', '2026-10')->firstOrFail();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/approve")->assertOk();
        $this->assertSame(0, (int) $correction->fresh()->outstanding_cents, '300.00 by hand + 200.00 from payroll = 500.00');
        $this->assertSame('recovered', $correction->fresh()->status);
    }

    // ------------------------------------------------------------------ 3. Permanent pay and attendance

    public function test_a_permanent_is_paid_the_full_monthly_salary_and_only_recorded_absences_reduce_it(): void
    {
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->company, null, 300, '2026-01-01');

        foreach ([[1, 'present'], [2, 'present'], [3, 'late'], [7, 'absent']] as [$day, $status]) {
            EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => sprintf('2026-09-%02d', $day), 'status' => $status]);
        }

        $this->generate('2026-09');
        $line = $this->line('2026-09', $assignment);

        $this->assertSame(30000, (int) $line->gross_cents, 'the basis is the full monthly salary: days without any attendance record are not absences');
        $this->assertSame(1000, (int) $line->other_deduction_cents, 'one recorded absence = one daily rate (300 / 30)');
        $this->assertSame(29000, (int) $line->net_cents);
        $this->assertSame(['2026-09-07'], $line->deductions()->where('kind', 'absence')->sole()->detail['absent_dates']);

        // An absence outside the assignment dates costs nothing.
        EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => '2025-12-31', 'status' => 'absent']);
        $this->generate('2026-09');
        $this->assertSame(29000, (int) $this->line('2026-09', $assignment)->net_cents);
    }

    // ------------------------------------------------------------------ 4. Temporary registered as Replacement

    public function test_the_hr_confirmation_for_a_temporary_worker_registered_as_a_replacement_is_kept_in_the_audit_history_and_nothing_is_converted(): void
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $temp = $this->makeEmployee('active', 'temporary');

        $this->replace($original, $temp)->assertUnprocessable()->assertJsonPath('error_code', 'TEMPORARY_REGISTERED_AS_REPLACEMENT');
        $this->assertSame(0, EmployeeWorkAssignment::query()->where('employee_id', $temp->id)->count(), 'no silent conversion or creation');

        $id = $this->replace($original, $temp, ['acknowledge_temporary_as_replacement' => true])->assertOk()->json('data.replacement.id');

        $assignment = EmployeeWorkAssignment::query()->findOrFail($id);
        $this->assertSame('replacement', $assignment->assignment_type->value, 'saved exactly as HR decided');
        $this->assertSame('temporary', $temp->fresh()->employment_type, "the employee's own type is not changed");

        $created = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $id)->where('action', 'create')->sole();
        $decision = $created->metadata['temporary_registered_as_replacement_confirmed'];
        $this->assertSame($this->superAdmin->id, $decision['confirmed_by_admin_id']);
        $this->assertSame($temp->id, $decision['employee_id']);
        $this->assertNotEmpty($decision['confirmed_at']);

        $replaced = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $original->id)->get()->first(fn ($l) => ($l->metadata['event'] ?? null) === 'replaced');
        $this->assertTrue($replaced->metadata['temporary_registered_as_replacement_confirmed']);
    }

    // ------------------------------------------------------------------ 5. Replacement follows the Permanent salary for the whole month

    private function permanentWithReplacement(): array
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $replacementId = $this->replace($original, $this->makeEmployee(), ['replacement_date' => '2026-09-16'])->assertOk()->json('data.replacement.id');

        return [$original->refresh(), EmployeeWorkAssignment::query()->findOrFail($replacementId)];
    }

    private function changeSalary(string $token, EmployeeWorkAssignment $assignment, int $amount, string $effective, array $over = [])
    {
        return $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$assignment->id}", array_replace(['salary_amount' => $amount, 'salary_effective_from' => $effective], $over));
    }

    public function test_a_permanent_salary_change_applies_to_the_replacement_for_the_whole_month_never_prorated_by_the_day(): void
    {
        [$original, $replacement] = $this->permanentWithReplacement();
        $hr = $this->hrManager();

        // 10 November: the Replacement gets the new salary from 1 November, not from the 10th.
        $this->changeSalary($hr, $original, 360, '2026-11-10')->assertOk();

        $history = $replacement->salaryHistories()->get();
        $this->assertSame(['2026-09-16', '2026-11-01'], $history->map(fn ($h) => $h->effective_from->toDateString())->all());
        $this->assertSame(['300.00', '360.00'], $history->map(fn ($h) => (string) $h->amount)->all(), 'the earlier salary row is kept');
        $this->assertStringContainsString("Permanent assignment #{$original->id}", (string) $history[1]->reason);
        $this->assertSame('360.00', (string) $replacement->fresh()->salary_amount);

        $this->generate('2026-10');
        $this->assertSame(30000, (int) $this->line('2026-10', $replacement)->gross_cents, 'October is unchanged: 300 for the whole month');
        $this->generate('2026-11');
        $this->assertSame(36000, (int) $this->line('2026-11', $replacement)->gross_cents, 'November: 30 days at 360, not 9 days at 300 and 21 at 360');
        $this->assertSame(30, $this->line('2026-11', $replacement)->eligible_days);

        // The change is audited on the Replacement with where it came from.
        $log = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $replacement->id)->get()->first(fn ($l) => ($l->metadata['source'] ?? null) === 'permanent_salary_change');
        $this->assertSame($original->id, $log->metadata['permanent_assignment_id']);
        $this->assertSame('2026-11-10', $log->metadata['permanent_change_effective']);
        $this->assertSame('2026-11-01', $log->metadata['applied_from']);
    }

    public function test_a_change_inside_the_month_the_replacement_started_applies_to_all_its_days_and_the_old_value_stays_in_the_audit(): void
    {
        [$original, $replacement] = $this->permanentWithReplacement();

        // The Permanent's salary changes on 20 September; the Replacement started on 16 September: all of its September days use 360.
        $this->changeSalary($this->hrManager(), $original, 360, '2026-09-20')->assertOk();

        $this->generate('2026-09');
        $this->assertSame(18000, (int) $this->line('2026-09', $replacement)->gross_cents, '15 days x 360 / 30: the change day does not split the month');
        $this->assertSame(15000, (int) $this->line('2026-09', $original)->gross_cents, 'the Permanent was paid for its own days before the change: unchanged');

        $log = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $replacement->id)->get()->first(fn ($l) => ($l->metadata['source'] ?? null) === 'permanent_salary_change');
        $this->assertSame('300.00', $log->metadata['previous_value_at_same_date']['amount'], 'the replaced value is preserved in the audit trail');
        $this->assertSame('2026-09-16', $log->metadata['applied_from']);
    }

    public function test_a_replacement_of_a_replacement_follows_too_and_a_later_permanent_is_not_affected(): void
    {
        [$original, $first] = $this->permanentWithReplacement();
        $second = EmployeeWorkAssignment::query()->findOrFail($this->as($this->super)->postJson($this->companyUrl("/{$first->id}/replace"), [
            'replacement_employee_id' => $this->makeEmployee()->id, 'replacement_date' => '2026-10-10', 'assignment_type' => 'replacement',
        ])->assertOk()->json('data.replacement.id'));
        $returned = EmployeeWorkAssignment::query()->findOrFail($this->as($this->super)->postJson($this->companyUrl("/{$second->id}/return"), [
            'return_date' => '2026-11-05', 'salary_amount' => 300, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertOk()->json('data.returned.id'));

        $this->changeSalary($this->hrManager(), $original, 400, '2026-10-25')->assertOk();

        $this->assertSame('400.00', (string) $first->fresh()->salary_amount, 'the first Replacement ended on 9 October: a change in October reaches the whole month, and it was active in October');
        $this->assertSame('400.00', (string) $second->fresh()->salary_amount);
        $this->assertSame('300.00', (string) $returned->fresh()->salary_amount, 'the returned Permanent has a salary of their own');
    }

    public function test_a_closed_month_is_never_rewritten_when_the_permanent_salary_changes_and_a_correction_needs_a_super_admin(): void
    {
        [$original, $replacement] = $this->permanentWithReplacement();
        $run = $this->close('2026-09');
        $sept = $this->line('2026-09', $replacement);
        $before = ['net' => $sept->net_cents, 'gross' => $sept->gross_cents, 'id' => $sept->id];
        $hr = $this->hrManager();

        // A change that starts in an open month leaves September alone and is open to HR.
        $this->changeSalary($hr, $original, 330, '2026-10-12')->assertOk();
        $this->assertSame(0, PayrollCorrection::query()->count());
        $this->assertSame($before, ['net' => $this->line('2026-09', $replacement)->net_cents, 'gross' => $this->line('2026-09', $replacement)->gross_cents, 'id' => $sept->id]);

        // A change effective in September would change what September paid the Replacement: Super Admin only, with a reason.
        $this->changeSalary($hr, $original, 360, '2026-09-20')->assertForbidden()->assertJsonPath('error_code', 'PAYROLL_CORRECTION_SUPER_ADMIN_ONLY');
        $this->changeSalary($this->super, $original, 360, '2026-09-20')->assertUnprocessable()->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->assertSame('330.00', (string) $replacement->fresh()->salary_amount, 'refused as a whole: the Replacement was not changed either');
        $this->changeSalary($this->super, $original, 360, '2026-09-20', ['correction_reason' => 'The raise was approved for 20 September'])->assertOk();

        $correction = PayrollCorrection::query()->where('employee_id', $replacement->employee_id)->sole();
        $this->assertSame('underpayment', $correction->kind, 'the Replacement should have been paid 15 x 360 / 30 = 180.00, not 150.00');
        $this->assertSame(3000, (int) $correction->outstanding_cents);
        $this->assertSame('finalized', $run->fresh()->status);
        $this->assertSame($before['net'], $this->line('2026-09', $replacement)->net_cents, 'the finalized line is unchanged');
    }

    public function test_a_replacements_own_salary_still_cannot_be_edited_and_points_to_the_permanent(): void
    {
        [, $replacement] = $this->permanentWithReplacement();

        $response = $this->changeSalary($this->super, $replacement, 999, '2026-11-01')->assertUnprocessable()->assertJsonPath('error_code', 'REPLACEMENT_SALARY_IS_INHERITED');
        $this->assertStringContainsString('whole month', $response->json('message'));
    }
}
