<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeePayment;
use App\Models\PayrollCorrection;
use App\Models\PayrollCorrectionTransaction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Models\Position;
use App\Support\Hr\Payroll\CorrectionSupersession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\Support\FinalizesCompanies;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * P1 (owner decision 2026-11-04). When a month is locked BEFORE it is worked, every October fix reaches the locked November and creates a November payroll
 * correction (R1). Reopening that company's November and generating it again must not leave the old correction open next to the rebuilt line, or the same pay
 * is counted twice (R2). Synthetic data only. Co: a1 300 (from 1 Oct). A newcomer is added from 1 Oct at 150, which November (finalized early) has never seen.
 */
class PayrollCorrectionSupersessionTest extends TestCase
{
    use FinalizesCompanies;
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $co;

    private Position $position;

    private Employee $newcomer;

    private ClientCompany $otherCo;

    private string $super;

    private string $hrManager;

    private int $novRunId;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
        [, $this->super] = $this->superAdminToken();
        $this->hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve']);
        $this->co = $this->makeCompany('Supersession Co');
        $this->position = Position::query()->create(['name' => 'Supervisor']);
        $this->makeAssignment($this->makeEmployee(), $this->co, null, 300, '2026-10-01');
        $this->newcomer = $this->makeEmployee();
        $this->otherCo = $this->makeCompany('Other Co');
        $this->makeAssignment($this->makeEmployee(), $this->otherCo, null, 200, '2026-10-01');

        $this->generate('2026-10');
        $this->novRunId = $this->generate('2026-11');
        $this->finalizeEveryCompany($this->novRunId, $this->super); // November locked BEFORE it is worked
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

    private function generate(string $period): int
    {
        return (int) $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated()->json('data.id');
    }

    private function addNewcomer(): void
    {
        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/client-companies/{$this->co->id}/employees", [
            'employee_id' => $this->newcomer->id, 'assignment_type' => 'permanent', 'position_id' => $this->position->id,
            'start_date' => '2026-10-01', 'salary_amount' => 150, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
            'correction_reason' => 'Added with an October start date',
        ])->assertCreated();
    }

    private function novSection(): PayrollRunSection
    {
        return PayrollRunSection::query()->where('payroll_run_id', $this->novRunId)->where('client_company_id', $this->co->id)->firstOrFail();
    }

    private function reopenNovember()
    {
        return $this->as($this->hrManager)->patchJson('/api/v1/admin/hr/payroll-run-sections/'.$this->novSection()->id.'/reopen', ['reason' => 'November was finalized before the month was worked']);
    }

    private function corrections(): Collection
    {
        return PayrollCorrection::query()->where('employee_id', $this->newcomer->id)->orderBy('id')->get();
    }

    // ------------------------------------------------------------------------------------------------ R1

    public function test_r1_an_october_dated_change_reaches_a_locked_november_and_becomes_a_november_correction(): void
    {
        $this->addNewcomer();

        $c = $this->corrections()->sole();
        $this->assertSame(['2026-11', 'underpayment', 15000, 'open'], [$c->period, $c->kind, (int) $c->outstanding_cents, $c->status]);
        $this->assertSame(0, (int) PayrollItem::query()->where('payroll_run_id', $this->novRunId)->where('employee_id', $this->newcomer->id)->count(), 'the locked November has no line for the newcomer');
    }

    // ------------------------------------------------------------------------------------------------ R2 and the guards around it

    public function test_r2_reopening_supersedes_the_open_correction_so_the_rebuilt_line_is_not_counted_twice(): void
    {
        $this->addNewcomer();
        $this->reopenNovember()->assertOk();

        $c = $this->corrections()->sole();
        $this->assertSame('superseded', $c->status, 'kept in the history, not deleted');
        $this->assertSame(15000, (int) $c->outstanding_cents, 'the amount stays recorded as it was');
        $this->assertSame($this->novSection()->id, (int) $c->superseded_section_id);
        $this->assertSame($this->hrManagerId(), (int) $c->superseded_by);
        $this->assertNotNull($c->superseded_at);
        $this->assertSame('November was finalized before the month was worked', $c->supersede_reason);

        CarbonImmutable::setTestNow('2026-11-05 10:30:00');
        $this->generate('2026-11');

        $line = (int) PayrollItem::query()->where('payroll_run_id', $this->novRunId)->where('employee_id', $this->newcomer->id)->sum('net_cents');
        $open = (int) PayrollCorrection::query()->where('employee_id', $this->newcomer->id)->where('status', 'open')->sum('outstanding_cents');
        $this->assertSame([15000, 0], [$line, $open], 'the pay appears once: as the regular line, not also as an open correction');

        $event = AuditLog::query()->where('entity_type', PayrollCorrection::class)->where('entity_id', $c->id)->get()->firstWhere(fn ($l) => ($l->metadata['event'] ?? '') === 'payroll_correction_superseded');
        $this->assertNotNull($event);
        $this->assertSame(['open', 'superseded'], [$event->metadata['before']['status'], $event->metadata['after']['status']]);
        $this->assertSame('hr_manager', $event->metadata['superseded_by_role']);
    }

    private function hrManagerId(): int
    {
        return (int) Admin::query()->where('role', AdminRole::HrManager)->value('id');
    }

    public function test_a_reopened_company_cannot_be_reviewed_or_finalized_until_the_month_was_generated_again(): void
    {
        $this->addNewcomer();
        $this->reopenNovember()->assertOk();
        $section = $this->novSection();

        // The newcomer's assignment was added AFTER the lines were generated: the freshness guard (source data) refuses the review and says what changed.
        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/review")
            ->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_SOURCES_CHANGED')->assertJsonPath('errors.changes.0.category', 'assignment')->assertJsonPath('errors.changes.0.change', 'added');
        $this->assertSame('draft', $section->fresh()->status, 'refused as a whole');

        CarbonImmutable::setTestNow('2026-11-05 11:00:00');
        $this->generate('2026-11');
        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->fresh()->id}/review")->assertOk();
        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->fresh()->id}/finalize")->assertOk();
        $this->assertSame('finalized', $section->fresh()->status);
        $this->assertSame(0, (int) PayrollCorrection::query()->where('status', 'open')->count(), 'no open correction is left behind');
    }

    public function test_a_reopened_company_whose_sources_did_not_change_is_not_blocked_by_the_freshness_guard(): void
    {
        // Nothing was assigned, re-priced or covered after the lines were generated, so reopening and finalizing again needs no extra Generate.
        $this->reopenNovember()->assertOk();
        $section = $this->novSection();

        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/review")->assertOk();
        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/finalize")->assertOk();
        $this->assertSame('finalized', $section->fresh()->status);
    }

    public function test_a_correction_with_money_already_paid_blocks_the_reopening_and_nothing_changes(): void
    {
        $this->addNewcomer();
        $correction = $this->corrections()->sole();
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions", ['type' => 'underpayment_payment', 'amount' => '50.00', 'transaction_date' => '2026-11-05', 'note' => 'Part paid', 'idempotency_key' => 'pay-underpayment-0001'])->assertStatus(201);

        $this->reopenNovember()->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_HAS_MONEY_MOVEMENT');

        $this->assertSame('finalized', $this->novSection()->status);
        $this->assertSame('open', $correction->fresh()->status);
        $this->assertNull($correction->fresh()->superseded_at);
    }

    public function test_only_the_reopened_company_and_month_are_affected(): void
    {
        $other = $this->otherCo;
        $this->addNewcomer();
        $foreign = PayrollCorrection::query()->create([
            'batch_key' => (string) Str::uuid(), 'payroll_run_id' => $this->novRunId, 'employee_id' => $this->newcomer->id, 'client_company_id' => $other->id, 'period' => '2026-11', 'kind' => 'underpayment', 'action' => 'assigned',
            'recorded_net_cents' => 0, 'corrected_net_cents' => 1000, 'difference_cents' => -1000, 'outstanding_cents' => 1000, 'status' => 'open', 'reason' => 'another company', 'corrected_by' => null,
        ]);

        $this->reopenNovember()->assertOk();

        $this->assertSame('open', $foreign->fresh()->status, "another company's correction is untouched");
        $this->assertSame('superseded', $this->corrections()->firstWhere('client_company_id', $this->co->id)->status);
        $this->assertSame('finalized', PayrollRunSection::query()->where('payroll_run_id', $this->novRunId)->where('client_company_id', $other->id)->value('status'));
    }

    public function test_the_ordinary_reopen_of_a_company_left_approved_by_an_earlier_version_supersedes_too(): void
    {
        $this->addNewcomer();
        $this->novSection()->update(['status' => PayrollRunSection::STATUS_APPROVED]); // the legacy status of the 112 November sections

        $this->reopenNovember()->assertOk();

        $this->assertSame('draft', $this->novSection()->status);
        $this->assertSame('superseded', $this->corrections()->sole()->status);
    }

    /** UI audit C-1: a superseded correction is history only; no money may be recorded against it and nothing else is touched by the refusal. */
    public function test_nothing_can_be_paid_against_a_superseded_underpayment_and_the_refusal_changes_nothing(): void
    {
        $this->addNewcomer();
        $this->reopenNovember()->assertOk();
        $correction = $this->corrections()->sole();
        $this->assertSame('superseded', $correction->status);

        $before = $this->moneySnapshot($correction);

        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions", ['type' => 'underpayment_payment', 'amount' => '10.00', 'transaction_date' => '2026-11-05', 'idempotency_key' => 'pay-superseded-0001'])
            ->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_SUPERSEDED');

        $this->assertSame($before, $this->moneySnapshot($correction), 'no transaction, no employee-payment row, the correction and every payroll line exactly as before');
        $this->assertSame(0, (int) EmployeePayment::query()->where('employee_id', $this->newcomer->id)->count());
    }

    public function test_nothing_can_be_repaid_or_written_off_against_a_superseded_overpayment(): void
    {
        $employee = $this->makeEmployee();
        $first = $this->makeAssignment($employee, $this->co, null, 300, '2026-01-01');
        $sept = $this->generate('2026-09');
        $this->finalizeEveryCompany($sept, $this->super);
        $this->as($this->super)->postJson("/api/v1/admin/hr/client-companies/{$this->co->id}/employees/{$first->id}/stop", ['end_date' => '2026-09-10', 'reason' => 'Left', 'correction_reason' => 'Left on 10 September'])->assertOk();
        $correction = PayrollCorrection::query()->where('employee_id', $employee->id)->where('kind', 'overpayment')->where('period', '2026-09')->sole();
        $septSection = PayrollRunSection::query()->where('payroll_run_id', $sept)->where('client_company_id', $this->co->id)->firstOrFail();
        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$septSection->id}/reopen", ['reason' => 'September is rebuilt with the true end date'])->assertOk();
        $this->assertSame('superseded', $correction->fresh()->status);

        $before = $this->moneySnapshot($correction);

        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions", ['type' => 'debt_repayment', 'amount' => '5.00', 'transaction_date' => '2026-11-05', 'idempotency_key' => 'repay-superseded-0001'])
            ->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_SUPERSEDED');
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions", ['type' => 'debt_write_off', 'amount' => '5.00', 'transaction_date' => '2026-11-05', 'reason' => 'Should not be possible', 'idempotency_key' => 'writeoff-superseded-0001'])
            ->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_SUPERSEDED');

        $this->assertSame($before, $this->moneySnapshot($correction));
    }

    /** @return array<string, mixed> every row a refused settlement must leave exactly as it was */
    private function moneySnapshot(PayrollCorrection $correction): array
    {
        return [
            'transactions' => PayrollCorrectionTransaction::query()->count(),
            'employee_payments' => EmployeePayment::query()->count(),
            'correction' => (function () use ($correction): array {
                $row = PayrollCorrection::query()->find($correction->id);

                return [$row->status, (int) $row->outstanding_cents, (int) $row->difference_cents, (string) $row->superseded_at];
            })(),
            'items' => PayrollItem::query()->orderBy('id')->get(['id', 'gross_cents', 'net_cents', 'other_deduction_cents', 'payment_status'])->toArray(),
        ];
    }

    public function test_a_superseded_overpayment_is_no_longer_recovered_from_later_salaries(): void
    {
        // September is finalized, an employee who really left on 10 September had been paid for the month (an OVERPAYMENT correction), then carries on at the same company in October.
        $employee = $this->makeEmployee();
        $first = $this->makeAssignment($employee, $this->co, null, 300, '2026-01-01');
        $sept = $this->generate('2026-09');
        $this->finalizeEveryCompany($sept, $this->super);
        $this->as($this->super)->postJson("/api/v1/admin/hr/client-companies/{$this->co->id}/employees/{$first->id}/stop", ['end_date' => '2026-09-10', 'reason' => 'Left', 'correction_reason' => 'Left on 10 September'])->assertOk();
        $correction = PayrollCorrection::query()->where('employee_id', $employee->id)->where('kind', 'overpayment')->where('period', '2026-09')->sole();
        // (an overpayment is recovered from the pay of the SAME company, so the employee carries on there from October)
        $this->makeAssignment($employee, $this->co, null, 300, '2026-10-01');
        $octId = $this->generate('2026-10');
        $recovered = (int) PayrollItem::query()->where('payroll_run_id', $octId)->where('employee_id', $employee->id)->sum('other_deduction_cents');
        $this->assertGreaterThan(0, $recovered, 'while the correction is open it is recovered from the next salary');

        $septSection = PayrollRunSection::query()->where('payroll_run_id', $sept)->where('client_company_id', $this->co->id)->firstOrFail();
        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$septSection->id}/reopen", ['reason' => 'September is rebuilt with the true end date'])->assertOk();
        $this->assertSame('superseded', $correction->fresh()->status);

        CarbonImmutable::setTestNow('2026-11-05 12:00:00');
        $octId = $this->generate('2026-10');
        $this->assertSame(0, (int) PayrollItem::query()->where('payroll_run_id', $octId)->where('employee_id', $employee->id)->sum('other_deduction_cents'), 'a superseded overpayment is not deducted any more');
        $this->assertSame('superseded', $correction->fresh()->status, 'regenerating a later month (which refreshes the recovery ledger) never opens a superseded correction again');
        $this->assertNotNull(PayrollRun::query()->find($octId));
    }

    // ------------------------------------------------------------------------------------------------ owner decision C-1 (2026-11-06)
    //
    // A transaction of a SUPERSEDED correction may be voided when the undo is clean. In the application a correction that already holds any transaction cannot be
    // superseded (CORRECTION_HAS_MONEY_MOVEMENT), so the situation is reproduced here by marking such a correction superseded directly, as older data could be.

    private function supersedeDirectly(PayrollCorrection $correction): void
    {
        $correction->forceFill(['status' => PayrollCorrection::STATUS_SUPERSEDED, 'superseded_at' => now(), 'supersede_reason' => 'Legacy test data'])->save();
    }

    public function test_c1_an_underpayment_payment_of_a_superseded_correction_is_cancelled_by_a_clean_linked_reversal(): void
    {
        $this->addNewcomer();
        $correction = $this->corrections()->sole();
        $paidId = $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions", ['type' => 'underpayment_payment', 'amount' => '50.00', 'transaction_date' => '2026-11-05', 'idempotency_key' => 'pay-c1-underpayment-0001'])->assertStatus(201)->json('data.transaction_id');
        $this->supersedeDirectly($correction);

        $original = PayrollCorrectionTransaction::query()->findOrFail($paidId);
        $originalRow = $original->getAttributes();
        $originalPayment = EmployeePayment::query()->findOrFail($original->employee_payment_id);
        $originalPaymentRow = $originalPayment->getAttributes();
        $items = PayrollItem::query()->orderBy('id')->get()->map->getAttributes()->all();
        $corrected = PayrollCorrection::query()->findOrFail($correction->id);
        $correctionBefore = [$corrected->status, (int) $corrected->outstanding_cents, (int) $corrected->difference_cents, (string) $corrected->superseded_at];

        // An HR Manager can never undo an underpayment payment, superseded or not; nothing is written by the refusal.
        $txBefore = PayrollCorrectionTransaction::query()->count();
        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$paidId}/void", ['reason' => 'HR Manager must not do this'])
            ->assertStatus(403)->assertJsonPath('error_code', 'PAYROLL_CORRECTION_NOT_ALLOWED');
        $this->assertSame($txBefore, PayrollCorrectionTransaction::query()->count());

        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$paidId}/void", ['reason' => 'Paid by mistake before the rebuild'])->assertOk();

        // A linked reversal was added; the original transaction and the original payment row are exactly as they were; the pair nets to zero.
        $this->assertSame($txBefore + 1, PayrollCorrectionTransaction::query()->count());
        $this->assertSame($originalRow, PayrollCorrectionTransaction::query()->findOrFail($paidId)->getAttributes());
        $this->assertSame($originalPaymentRow, EmployeePayment::query()->findOrFail($originalPayment->id)->getAttributes());
        $reversal = PayrollCorrectionTransaction::query()->where('reverses_transaction_id', $paidId)->sole();
        $this->assertSame(0, (int) round(((float) EmployeePayment::query()->whereIn('id', [$originalPayment->id, $reversal->employee_payment_id])->sum('amount')) * 100));

        // The correction stays superseded with its amounts and its history; payroll lines are untouched; the audit trail says it was superseded.
        $corrected = PayrollCorrection::query()->findOrFail($correction->id);
        $this->assertSame($correctionBefore, [$corrected->status, (int) $corrected->outstanding_cents, (int) $corrected->difference_cents, (string) $corrected->superseded_at]);
        $this->assertSame($items, PayrollItem::query()->orderBy('id')->get()->map->getAttributes()->all());
        $log = AuditLog::query()->where('entity_type', PayrollCorrection::class)->where('entity_id', $correction->id)->latest('id')->firstOrFail();
        $this->assertSame('underpayment_payment_voided', $log->metadata['event']);
        $this->assertTrue($log->metadata['correction_superseded']);
        $this->assertSame(0, $log->metadata['employee_payment_ledger_net_cents']);

        // It can be reversed only once, and nothing new can be recorded against a superseded correction.
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$paidId}/void", ['reason' => 'A second time'])
            ->assertStatus(422)->assertJsonPath('error_code', 'TRANSACTION_ALREADY_VOIDED');
        $this->as($this->super)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions", ['type' => 'underpayment_payment', 'amount' => '10.00', 'transaction_date' => '2026-11-05', 'idempotency_key' => 'pay-c1-underpayment-0002'])
            ->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_SUPERSEDED');
    }

    public function test_c1_a_repayment_of_a_superseded_overpayment_is_voided_cleanly_by_an_hr_manager(): void
    {
        $employee = $this->makeEmployee();
        $first = $this->makeAssignment($employee, $this->co, null, 300, '2026-01-01');
        $sept = $this->generate('2026-09');
        $this->finalizeEveryCompany($sept, $this->super);
        $this->as($this->super)->postJson("/api/v1/admin/hr/client-companies/{$this->co->id}/employees/{$first->id}/stop", ['end_date' => '2026-09-10', 'reason' => 'Left', 'correction_reason' => 'Left on 10 September'])->assertOk();
        $correction = PayrollCorrection::query()->where('employee_id', $employee->id)->where('kind', 'overpayment')->where('period', '2026-09')->sole();
        $repaidId = $this->as($this->hrManager)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions", ['type' => 'debt_repayment', 'amount' => '5.00', 'transaction_date' => '2026-11-05', 'idempotency_key' => 'repay-c1-0001'])->assertStatus(201)->json('data.transaction_id');
        $this->supersedeDirectly($correction);

        $items = PayrollItem::query()->orderBy('id')->get()->map->getAttributes()->all();
        $corrected = PayrollCorrection::query()->findOrFail($correction->id);
        $correctionBefore = [$corrected->status, (int) $corrected->outstanding_cents, (int) $corrected->difference_cents, (string) $corrected->superseded_at];
        $txBefore = PayrollCorrectionTransaction::query()->count();

        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$repaidId}/void", ['reason' => 'Repayment recorded in error'])->assertOk();

        // The row is marked void with who / when / why, nothing is deleted or added, the correction stays superseded and every payroll line is as it was.
        $tx = PayrollCorrectionTransaction::query()->findOrFail($repaidId);
        $this->assertTrue($tx->isVoided());
        $this->assertSame('Repayment recorded in error', $tx->void_reason);
        $this->assertSame($txBefore, PayrollCorrectionTransaction::query()->count());
        $corrected = PayrollCorrection::query()->findOrFail($correction->id);
        $this->assertSame($correctionBefore, [$corrected->status, (int) $corrected->outstanding_cents, (int) $corrected->difference_cents, (string) $corrected->superseded_at]);
        $this->assertSame($items, PayrollItem::query()->orderBy('id')->get()->map->getAttributes()->all());
        $log = AuditLog::query()->where('entity_type', PayrollCorrection::class)->where('entity_id', $correction->id)->latest('id')->firstOrFail();
        $this->assertSame('debt_repayment_voided', $log->metadata['event']);
        $this->assertTrue($log->metadata['correction_superseded']);

        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$repaidId}/void", ['reason' => 'A second time'])
            ->assertStatus(422)->assertJsonPath('error_code', 'TRANSACTION_ALREADY_VOIDED');
    }

    // ------------------------------------------------------------------------------------------------ C-1: clean undo versus reopening (Final Remediation Audit, 2026-11-06)
    //
    // Voiding is a clean undo of one ledger row. It does not, by itself, give anyone permission to reopen payroll: the reopening gate counts every transaction of an
    // OPEN correction (voided ones too) and ignores corrections that are already superseded. These tests pin that behaviour so the undo can never widen it.

    /** @return array{0: PayrollCorrection, 1: PayrollRunSection} an open September overpayment (an employee who left on 10 September had been paid for the month) */
    private function openSeptemberOverpayment(): array
    {
        $employee = $this->makeEmployee();
        $first = $this->makeAssignment($employee, $this->co, null, 300, '2026-01-01');
        $sept = $this->generate('2026-09');
        $this->finalizeEveryCompany($sept, $this->super);
        $this->as($this->super)->postJson("/api/v1/admin/hr/client-companies/{$this->co->id}/employees/{$first->id}/stop", ['end_date' => '2026-09-10', 'reason' => 'Left', 'correction_reason' => 'Left on 10 September'])->assertOk();

        return [
            PayrollCorrection::query()->where('employee_id', $employee->id)->where('kind', 'overpayment')->where('period', '2026-09')->sole(),
            PayrollRunSection::query()->where('payroll_run_id', $sept)->where('client_company_id', $this->co->id)->firstOrFail(),
        ];
    }

    private function repay(PayrollCorrection $correction, string $amount, string $key): int
    {
        return (int) $this->as($this->hrManager)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions", ['type' => 'debt_repayment', 'amount' => $amount, 'transaction_date' => '2026-11-05', 'idempotency_key' => $key])->assertStatus(201)->json('data.transaction_id');
    }

    public function test_c1_voiding_one_of_two_repayments_restores_only_that_amount_and_voiding_both_restores_the_whole_debt(): void
    {
        [$correction] = $this->openSeptemberOverpayment();
        $whole = (int) $correction->fresh()->outstanding_cents;
        $this->assertGreaterThan(800, $whole);

        $first = $this->repay($correction, '5.00', 'repay-c1-two-0001');
        $second = $this->repay($correction, '3.00', 'repay-c1-two-0002');
        $this->assertSame($whole - 800, (int) $correction->fresh()->outstanding_cents);

        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$first}/void", ['reason' => 'The first repayment was entered twice'])->assertOk();
        $this->assertSame($whole - 300, (int) $correction->fresh()->outstanding_cents, 'only the voided 5.00 came back');
        $this->assertFalse(PayrollCorrectionTransaction::query()->findOrFail($second)->isVoided(), 'the other repayment is untouched');

        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$second}/void", ['reason' => 'The second one was also a mistake'])->assertOk();
        $fresh = $correction->fresh();
        $this->assertSame($whole, (int) $fresh->outstanding_cents, 'with every repayment voided the debt is whole again');
        $this->assertSame('open', $fresh->status);

        // Both rows are still in the ledger as history (voided, with who / why), and neither can be voided a second time: no double effect.
        $this->assertSame(2, PayrollCorrectionTransaction::query()->where('payroll_correction_id', $correction->id)->whereNotNull('voided_at')->count());
        foreach ([$first, $second] as $id) {
            $this->as($this->hrManager)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$id}/void", ['reason' => 'Again'])->assertStatus(422)->assertJsonPath('error_code', 'TRANSACTION_ALREADY_VOIDED');
        }
        $this->assertSame($whole, (int) $correction->fresh()->outstanding_cents);
    }

    public function test_c1_a_correction_whose_every_transaction_was_voided_still_blocks_the_reopening_for_everyone_and_nothing_changes(): void
    {
        [$correction, $septSection] = $this->openSeptemberOverpayment();
        $id = $this->repay($correction, '5.00', 'repay-c1-block-0001');
        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$id}/void", ['reason' => 'Recorded in error'])->assertOk();

        $before = $this->moneySnapshot($correction);
        $auditBefore = AuditLog::query()->count();

        foreach ([$this->hrManager, $this->super] as $who) {
            $this->as($who)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$septSection->id}/reopen", ['reason' => 'September is rebuilt with the true end date'])
                ->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_HAS_MONEY_MOVEMENT');
        }

        $this->assertSame('finalized', $septSection->fresh()->status, 'a clean undo is not a permission to reopen');
        $this->assertSame($before, $this->moneySnapshot($correction));
        $this->assertSame($auditBefore, AuditLog::query()->count());
    }

    public function test_c1_a_superseded_correction_never_blocks_a_reopening_even_when_it_holds_a_transaction(): void
    {
        [$correction, $septSection] = $this->openSeptemberOverpayment();
        $id = $this->repay($correction, '5.00', 'repay-c1-gate-0001');

        // While it is OPEN, the transaction (even once voided) is what blocks the reopening.
        $blocked = null;
        try {
            CorrectionSupersession::assertCanSupersede($septSection, '2026-09');
        } catch (HttpResponseException $e) {
            $blocked = $e->getResponse()->getData(true)['error_code'] ?? null;
        }
        $this->assertSame('CORRECTION_HAS_MONEY_MOVEMENT', $blocked);

        // Once superseded, the gate no longer looks at it: the existing rule, unchanged, and no new exception was added for it.
        $this->supersedeDirectly($correction);
        CorrectionSupersession::assertCanSupersede($septSection, '2026-09');
        $this->addToAssertionCount(1);

        // The clean undo of its transaction changes nothing about that, and the correction stays superseded.
        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions/{$id}/void", ['reason' => 'Recorded in error'])->assertOk();
        CorrectionSupersession::assertCanSupersede($septSection, '2026-09');
        $this->assertSame('superseded', $correction->fresh()->status);
        $this->assertSame('finalized', $septSection->fresh()->status, 'checking the gate reopened nothing');
    }
}
