<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Models\PayrollItem;
use App\Models\PayrollItemDeduction;
use App\Models\PayrollRecoveryDeduction;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner rules for correcting assignment/salary data that reaches an approved or finalized payroll month:
 * Super Admin only, with a reason, the closed run is never rewritten, the difference is recorded and linked, an overpayment is
 * recovered from later salaries (the lesser of what is owed and what the employee is payable that month, carried forward until zero,
 * never twice), and only the months the change really touches are locked.
 */
class ClosedPayrollCorrectionTest extends TestCase
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

    private function edit(string $token, EmployeeWorkAssignment $assignment, array $payload)
    {
        return $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$assignment->id}", $payload);
    }

    /** Generates, reviews and approves (and optionally finalizes) a month through the real endpoints. */
    private function close(string $period, bool $finalize = true): PayrollRun
    {
        $id = $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated()->json('data.id');
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/approve")->assertOk();

        if ($finalize) {
            $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/finalize")->assertOk();
        }

        return PayrollRun::query()->findOrFail($id);
    }

    private function line(string $period, EmployeeWorkAssignment $assignment): PayrollItem
    {
        return PayrollItem::query()->where('period', $period)->where('work_assignment_id', $assignment->id)->firstOrFail();
    }

    // ------------------------------------------------------------------ who may correct, and why

    public function test_only_a_super_admin_can_correct_data_that_affects_finalized_payroll_even_through_direct_api_calls(): void
    {
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $this->close('2026-09');

        $hrManager = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $hrManager->forceFill(['role' => AdminRole::HrManager])->save();
        $tokens = [
            'an HR editor with hr.manage' => $this->tokenWithOnly(['hr.view', 'hr.manage']),
            'a Marketing Manager' => Admin::factory()->create(['role' => AdminRole::MarketingManager])->createToken('t')->plainTextToken,
            'a Mobile App Manager' => Admin::factory()->create(['role' => AdminRole::MobileAppManager])->createToken('t')->plainTextToken,
            'someone with no permissions' => $this->noPermissionToken(),
        ];

        foreach ($tokens as $who => $token) {
            $response = $this->edit($token, $assignment, ['start_date' => '2026-09-21', 'correction_reason' => 'Trying to correct it anyway']);
            $this->assertContains($response->status(), [403], "{$who} must be refused");
        }

        // The HR editor reaches the rule itself and is told only a Super Admin may do this.
        $this->edit($tokens['an HR editor with hr.manage'], $assignment, ['start_date' => '2026-09-21', 'correction_reason' => 'Trying'])
            ->assertForbidden()->assertJsonPath('error_code', 'PAYROLL_CORRECTION_SUPER_ADMIN_ONLY');

        $this->assertSame('2026-01-01', $assignment->fresh()->start_date->toDateString(), 'nothing was changed');
        $this->assertSame(0, PayrollCorrection::query()->count());

        $this->edit($this->super, $assignment, ['start_date' => '2026-09-21', 'correction_reason' => 'The employee actually started on 21 September'])->assertOk();
        $this->assertSame(1, PayrollCorrection::query()->count());
    }

    public function test_a_correction_reason_is_mandatory(): void
    {
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $this->close('2026-09');

        $this->edit($this->super, $assignment, ['start_date' => '2026-09-21'])->assertUnprocessable()->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->edit($this->super, $assignment, ['start_date' => '2026-09-21', 'correction_reason' => '   '])->assertUnprocessable()->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->edit($this->super, $assignment, ['start_date' => '2026-09-21', 'correction_reason' => 'ab'])->assertUnprocessable()->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');

        $this->assertSame('2026-01-01', $assignment->fresh()->start_date->toDateString(), 'refused as a whole: the assignment is unchanged');
        $this->assertSame(0, PayrollCorrection::query()->count());
    }

    // ------------------------------------------------------------------ the closed month is preserved; the difference is recorded

    public function test_the_finalized_payroll_stays_exactly_as_it_was_and_the_overpayment_is_recorded_and_linked(): void
    {
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->company, null, 300, '2026-01-01');
        $run = $this->close('2026-09');
        $itemBefore = $this->line('2026-09', $assignment)->only(['id', 'gross_cents', 'net_cents', 'eligible_days', 'employee_id', 'work_assignment_id']);
        $totalsBefore = $run->fresh()->totals;

        $this->edit($this->super, $assignment, ['start_date' => '2026-09-21', 'correction_reason' => 'Employee started on 21 September, the file was wrong'])->assertOk();

        // The original finalized record is untouched.
        $this->assertSame('finalized', $run->fresh()->status);
        $this->assertSame($totalsBefore, $run->fresh()->totals);
        $this->assertSame(1, PayrollItem::query()->where('payroll_run_id', $run->id)->count());
        $this->assertSame($itemBefore, $this->line('2026-09', $assignment)->only(['id', 'gross_cents', 'net_cents', 'eligible_days', 'employee_id', 'work_assignment_id']));
        $this->assertSame(30000, (int) $this->line('2026-09', $assignment)->net_cents);

        // The correction is a separate, linked record: paid 300.00, should have been 10 days = 100.00.
        $correction = PayrollCorrection::query()->sole();
        $this->assertSame($employee->id, $correction->employee_id);
        $this->assertSame($assignment->id, $correction->work_assignment_id);
        $this->assertSame($run->id, $correction->payroll_run_id);
        $this->assertSame($itemBefore['id'], $correction->payroll_item_id);
        $this->assertSame('2026-09', $correction->period);
        $this->assertSame('overpayment', $correction->kind);
        $this->assertSame(30000, (int) $correction->recorded_net_cents);
        $this->assertSame(10000, (int) $correction->corrected_net_cents);
        $this->assertSame(20000, (int) $correction->difference_cents);
        $this->assertSame(20000, (int) $correction->outstanding_cents);
        $this->assertSame('open', $correction->status);
        $this->assertSame($this->superAdmin->id, $correction->corrected_by);
        $this->assertSame('Employee started on 21 September, the file was wrong', $correction->reason);
        $this->assertSame('2026-01-01', $correction->old_values['start_date']);
        $this->assertSame('2026-09-21', $correction->new_values['start_date']);

        $log = AuditLog::query()->where('entity_type', PayrollCorrection::class)->where('entity_id', $correction->id)->sole();
        $this->assertSame('payroll_corrected', $log->metadata['event']);
        $this->assertSame(20000, $log->metadata['difference_cents']);
        $this->assertSame($this->superAdmin->id, $log->admin_id);
    }

    public function test_the_overpayment_is_deducted_in_the_next_payable_month_and_the_ledger_reconciles(): void
    {
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $this->close('2026-09');
        $this->edit($this->super, $assignment, ['start_date' => '2026-09-21', 'correction_reason' => 'Wrong start date in the file'])->assertOk();
        $correction = PayrollCorrection::query()->sole();

        $october = $this->close('2026-10', false);
        $line = $this->line('2026-10', $assignment);

        $this->assertSame(30000, (int) $line->gross_cents);
        $this->assertSame(20000, (int) $line->other_deduction_cents, 'the salary (300.00) covers the whole 200.00 owed, so October pays 100.00');
        $this->assertSame(10000, (int) $line->net_cents);
        $deduction = PayrollItemDeduction::query()->where('payroll_item_id', $line->id)->where('kind', 'overpayment_recovery')->sole();
        $this->assertSame($correction->id, (int) $deduction->source_id, 'every recovery deduction is linked to the original correction');
        $this->assertSame('2026-09', $deduction->detail['source_period']);

        $ledger = PayrollRecoveryDeduction::query()->sole();
        $this->assertSame($correction->id, $ledger->payroll_correction_id);
        $this->assertSame('2026-10', $ledger->period);
        $this->assertSame(20000, (int) $ledger->amount_cents);
        $this->assertSame('applied', $ledger->status);

        $correction->refresh();
        $this->assertSame(0, (int) $correction->outstanding_cents, 'original overpayment minus every recovery = 0');
        $this->assertSame('recovered', $correction->status);

        // No further deductions once the balance is zero.
        $this->close('2026-11', false);
        $this->assertSame(0, PayrollItemDeduction::query()->where('kind', 'overpayment_recovery')->where('period', '2026-11')->count());
        $this->assertSame(30000, (int) $this->line('2026-11', $assignment)->net_cents);
        $this->assertSame('finalized', PayrollRun::query()->where('period', '2026-09')->value('status'));
        $this->assertSame('approved', $october->fresh()->status);
    }

    // ------------------------------------------------------------------ carrying the balance forward

    private function owed(Employee $employee, int $cents, string $period = '2026-09'): PayrollCorrection
    {
        $run = PayrollRun::query()->firstOrCreate(['period' => $period], ['status' => 'finalized']);

        return PayrollCorrection::query()->create([
            'batch_key' => (string) Str::uuid(), 'employee_id' => $employee->id, 'payroll_run_id' => $run->id, 'period' => $period,
            'kind' => 'overpayment', 'action' => 'edited', 'recorded_net_cents' => $cents, 'corrected_net_cents' => 0, 'difference_cents' => $cents,
            'outstanding_cents' => $cents, 'status' => 'open', 'reason' => 'earlier overpayment', 'corrected_by' => $this->superAdmin->id,
        ]);
    }

    public function test_an_insufficient_salary_pays_back_what_it_can_each_month_until_the_last_month_takes_only_the_remainder(): void
    {
        // 500.00 owed, 200.00 payable a month: 200 + 200 + 100.
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->company, null, 200, '2026-01-01');
        $correction = $this->owed($employee, 50000);

        $expected = [['2026-10', 20000, 0, 30000], ['2026-11', 20000, 0, 10000], ['2026-12', 10000, 10000, 0]];

        foreach ($expected as [$period, $deducted, $net, $outstandingAfter]) {
            $this->close($period, false);
            $line = $this->line($period, $assignment);

            $this->assertSame($deducted, (int) $line->other_deduction_cents, "{$period}: the lesser of what is owed and the payable salary");
            $this->assertSame(20000 - $deducted, (int) $line->net_cents, "{$period}: never more than the salary, the rest is paid");
            $this->assertSame($outstandingAfter, (int) $correction->fresh()->outstanding_cents, "{$period}: the balance carries forward");
        }

        $this->assertSame('recovered', $correction->fresh()->status);
        $this->assertSame(10000, (int) $this->line('2026-12', $assignment)->net_cents, 'in the final month the employee keeps the rest of the salary (100.00)');

        $this->close('2027-01', false);
        $this->assertSame(0, PayrollItemDeduction::query()->where('kind', 'overpayment_recovery')->where('period', '2027-01')->count());
        $this->assertSame(
            50000,
            (int) PayrollRecoveryDeduction::query()->where('payroll_correction_id', $correction->id)->sum('amount_cents'),
            'original overpayment = the sum of every successful recovery deduction',
        );
    }

    public function test_a_month_without_payable_salary_creates_no_deduction_and_the_balance_carries_to_the_next_payable_month(): void
    {
        $employee = $this->makeEmployee();
        $first = $this->makeAssignment($employee, $this->company, null, 200, '2026-01-01');
        $first->update(['status' => 'ended', 'end_date' => '2026-10-31']);
        $second = $this->makeAssignment($employee, $this->company, null, 200, '2026-12-01');
        $correction = $this->owed($employee, 30000);

        $this->close('2026-10', false);
        $this->assertSame(10000, (int) $correction->fresh()->outstanding_cents, 'October pays back 200.00');

        $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-11'])->assertCreated();
        $this->assertSame(0, PayrollItem::query()->where('period', '2026-11')->count(), 'November: the employee has no payable salary');
        $this->assertSame(0, PayrollRecoveryDeduction::query()->where('period', '2026-11')->count(), 'so there is no deduction for November');
        $this->assertSame(10000, (int) $correction->fresh()->outstanding_cents, 'the full balance carries forward');

        $this->close('2026-12', false);
        $this->assertSame(10000, (int) $this->line('2026-12', $second)->other_deduction_cents);
        $this->assertSame(10000, (int) $this->line('2026-12', $second)->net_cents);
        $this->assertSame(0, (int) $correction->fresh()->outstanding_cents);
    }

    public function test_repeated_processing_can_never_create_a_duplicate_deduction(): void
    {
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->company, null, 300, '2026-01-01');
        $correction = $this->owed($employee, 20000);

        // Regenerating the draft rebuilds it; it never stacks a second deduction.
        $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertCreated();
        $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertCreated();
        $this->assertSame(1, PayrollItemDeduction::query()->where('kind', 'overpayment_recovery')->count());
        $this->assertSame(1, PayrollRecoveryDeduction::query()->count());
        $this->assertSame('scheduled', PayrollRecoveryDeduction::query()->sole()->status);
        $this->assertSame(20000, (int) $correction->fresh()->outstanding_cents, 'a draft month has not recovered anything yet');

        // Approve, reopen, regenerate, approve again: still exactly one deduction of 200.00.
        $run = PayrollRun::query()->where('period', '2026-10')->firstOrFail();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/approve")->assertOk();
        $this->assertSame(0, (int) $correction->fresh()->outstanding_cents);
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/reopen", ['reason' => 'Check the totals again'])->assertOk();
        $this->assertSame(20000, (int) $correction->fresh()->outstanding_cents, 'reopening puts the deduction back to scheduled');
        $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertCreated();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/approve")->assertOk();

        $this->assertSame(1, PayrollRecoveryDeduction::query()->count());
        $this->assertSame(10000, (int) $this->line('2026-10', $assignment)->net_cents);
        $this->assertSame(0, (int) $correction->fresh()->outstanding_cents);

        // The database itself refuses a second row for the same correction and month.
        $this->expectException(QueryException::class);
        PayrollRecoveryDeduction::query()->create(['payroll_correction_id' => $correction->id, 'period' => '2026-10', 'amount_cents' => 1, 'status' => 'applied']);
    }

    public function test_repeating_the_same_correction_request_records_it_only_once(): void
    {
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $this->close('2026-09');
        $payload = ['start_date' => '2026-09-21', 'correction_reason' => 'Wrong start date in the file'];

        $this->edit($this->super, $assignment, $payload)->assertOk();
        $this->edit($this->super, $assignment, $payload)->assertOk();

        $this->assertSame(1, PayrollCorrection::query()->count(), 'the second identical request changes nothing, so it records nothing');
        $this->assertSame(20000, (int) PayrollCorrection::query()->sole()->difference_cents);
    }

    // ------------------------------------------------------------------ only the affected months

    public function test_only_the_payroll_months_that_are_actually_affected_are_locked(): void
    {
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $this->close('2026-08');
        $this->close('2026-09');
        $hr = $this->tokenWithOnly(['hr.view', 'hr.manage']);

        // A change that starts after both finalized months does not need a Super Admin.
        $this->edit($hr, $assignment, ['salary_amount' => 350, 'salary_effective_from' => '2026-11-01'])->assertOk();
        $this->assertSame(0, PayrollCorrection::query()->count());

        // Ending the assignment inside September changes September (and later) but not August.
        $this->edit($hr, $assignment, ['end_date' => '2026-09-10'])->assertForbidden();
        $this->edit($this->super, $assignment, ['end_date' => '2026-09-10', 'correction_reason' => 'The contract ended on 10 September'])->assertOk();

        $this->assertSame(['2026-09'], PayrollCorrection::query()->pluck('period')->all(), 'August was not touched, so August has no correction');
        $this->assertSame(30000, (int) $this->line('2026-08', $assignment)->net_cents);
        $this->assertSame('finalized', PayrollRun::query()->where('period', '2026-08')->value('status'));
    }

    // ------------------------------------------------------------------ other changes through the same rule

    public function test_replacing_an_employee_inside_a_finalized_month_records_an_overpayment_and_an_underpayment_that_nothing_pays_automatically(): void
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $standIn = $this->makeEmployee();
        $this->close('2026-09');
        $body = ['replacement_employee_id' => $standIn->id, 'replacement_date' => '2026-09-16', 'assignment_type' => 'replacement'];
        $url = "/api/v1/admin/hr/client-companies/{$this->company->id}/employees/{$original->id}/replace";

        $this->as($this->super)->postJson($url, $body)->assertUnprocessable()->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->assertSame('active', $original->fresh()->status->value);
        $this->as($this->super)->postJson($url, [...$body, 'correction_reason' => 'The replacement started on 16 September'])->assertOk();

        $over = PayrollCorrection::query()->where('kind', 'overpayment')->sole();
        $under = PayrollCorrection::query()->where('kind', 'underpayment')->sole();
        $this->assertSame($original->employee_id, $over->employee_id);
        $this->assertSame(15000, (int) $over->difference_cents, 'the original was paid 15 days too many');
        $this->assertSame($standIn->id, $under->employee_id);
        $this->assertSame(-15000, (int) $under->difference_cents, 'the replacement was never paid for 16-30 September');
        $this->assertSame('open', $under->status, 'an underpayment is an amount owed to the employee until a Super Admin pays it as a separate transaction');
        $this->assertSame(15000, (int) $under->outstanding_cents, 'the amount owed is recorded separately from the closed payroll month');

        // No automatic payment appears in the next month for the underpaid employee.
        $this->close('2026-10', false);
        $this->assertSame(0, PayrollRecoveryDeduction::query()->where('payroll_correction_id', $under->id)->count());
        $this->assertSame(0, (int) PayrollItem::query()->where('period', '2026-10')->where('employee_id', $standIn->id)->sum('adjustment_cents'));
    }

    // ------------------------------------------------------------------ visibility

    public function test_the_corrections_ledger_is_visible_to_payroll_viewers_only(): void
    {
        $employee = $this->makeEmployee();
        $this->owed($employee, 30000);

        $this->as($this->tokenWithOnly(['hr.view', 'hr.manage']))->getJson('/api/v1/admin/hr/payroll-corrections')->assertForbidden();

        $data = $this->as($this->tokenWithOnly(['hr.payroll.view']))->getJson('/api/v1/admin/hr/payroll-corrections')->assertOk()->json('data');
        $this->assertSame(30000, $data['corrections'][0]['difference_cents']);
        $this->assertSame(30000, $data['corrections'][0]['outstanding_cents']);
        $this->assertSame(30000, $data['totals']['outstanding_cents']);
        $this->assertSame('USD', $data['totals']['currency']);
    }
}
