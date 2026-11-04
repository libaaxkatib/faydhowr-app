<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\PayrollCorrection;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Models\Position;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

    public function test_a_reopened_company_cannot_be_finalized_until_the_month_was_generated_again(): void
    {
        $this->addNewcomer();
        $this->reopenNovember()->assertOk();
        $section = $this->novSection();

        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/review")->assertOk();
        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/finalize")->assertStatus(422)->assertJsonPath('error_code', 'REGENERATE_AFTER_REOPEN');
        $this->assertSame('reviewed', $section->fresh()->status, 'refused as a whole');

        CarbonImmutable::setTestNow('2026-11-05 11:00:00');
        $this->generate('2026-11');
        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->fresh()->id}/review")->assertOk();
        $this->as($this->hrManager)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->fresh()->id}/finalize")->assertOk();
        $this->assertSame('finalized', $section->fresh()->status);
        $this->assertSame(0, (int) PayrollCorrection::query()->where('status', 'open')->count(), 'no open correction is left behind');
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
            'batch_key' => 'x', 'payroll_run_id' => $this->novRunId, 'employee_id' => $this->newcomer->id, 'client_company_id' => $other->id, 'period' => '2026-11', 'kind' => 'underpayment', 'action' => 'assigned',
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
        $this->assertNotNull(PayrollRun::query()->find($octId));
    }
}
