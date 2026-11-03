<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\Hr\Payroll\PayrollPeriodLock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The payroll input locks are per COMPANY: finalizing Alpha must not freeze Bravo's attendance, penalties, advances, leave or workdays, while
 * Alpha's own stay protected. One test per call site (there are eight), plus the employee who works for two companies and the month that is locked
 * as a whole. Synthetic data only.
 *
 *   Alpha: a (employee), Bravo: b (employee), X works for BOTH (first assignment, the lowest id, at Alpha).
 */
class CompanyPayrollInputLocksTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const P = '2026-09';

    private ClientCompany $alpha;

    private ClientCompany $bravo;

    private Employee $a;

    private Employee $b;

    private Employee $x;

    private EmployeeWorkAssignment $aAssignment;

    private EmployeeWorkAssignment $bAssignment;

    private string $super;

    private int $runId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-12-20 10:00:00');
        [, $this->super] = $this->superAdminToken();

        $this->alpha = $this->makeCompany('Alpha Co');
        $this->bravo = $this->makeCompany('Bravo Co');
        $this->x = $this->makeEmployee();
        $this->makeAssignment($this->x, $this->alpha, null, 100);
        $this->makeAssignment($this->x, $this->bravo, null, 150);
        $this->a = $this->makeEmployee();
        $this->aAssignment = $this->makeAssignment($this->a, $this->alpha, null, 300);
        $this->b = $this->makeEmployee();
        $this->bAssignment = $this->makeAssignment($this->b, $this->bravo, null, 400);
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

    private function generate(): void
    {
        $this->runId = (int) $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => self::P])->assertCreated()->json('data.id');
    }

    /** Takes ONE company through review / approve / finalize (or stops at approve). */
    private function close(ClientCompany $company, bool $finalize = true): void
    {
        $section = PayrollRunSection::query()->where('payroll_run_id', $this->runId)->where('client_company_id', $company->id)->firstOrFail();

        foreach ($finalize ? ['review', 'approve', 'finalize'] : ['review', 'approve'] as $step) {
            $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/{$step}")->assertOk();
        }
    }

    /** The company a record belongs to, when the person recording it names one (an employee of a single company never has to). */
    private function owned(?ClientCompany $company): array
    {
        return $company === null ? [] : ['client_company_id' => $company->id];
    }

    private function attendance(Employee $employee, string $date = '2026-09-10', ?ClientCompany $company = null)
    {
        return $this->as($this->super)->postJson("/api/v1/admin/hr/employees/{$employee->id}/attendance", ['date' => $date, 'status' => 'absent', ...$this->owned($company)]);
    }

    private function penalty(Employee $employee, ?ClientCompany $company = null)
    {
        return $this->as($this->super)->postJson("/api/v1/admin/hr/employees/{$employee->id}/penalties", ['penalty_date' => '2026-09-10', 'reason' => 'Late', 'deduction_amount' => 5, 'currency' => 'USD', 'payroll_period' => self::P, ...$this->owned($company)]);
    }

    private function advance(Employee $employee, ?ClientCompany $company = null)
    {
        return $this->as($this->super)->postJson("/api/v1/admin/hr/employees/{$employee->id}/advances", ['advance_date' => '2026-09-10', 'amount' => 10, 'currency' => 'USD', 'payroll_period' => self::P, 'reason' => 'Rent', ...$this->owned($company)]);
    }

    private function leave(Employee $employee, ?ClientCompany $company = null, string $from = '2026-09-10', string $to = '2026-09-12')
    {
        return $this->as($this->super)->postJson("/api/v1/admin/hr/employees/{$employee->id}/leaves", ['leave_type' => 'unpaid', 'start_date' => $from, 'end_date' => $to, ...$this->owned($company)]);
    }

    private function refused($response): void
    {
        $response->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_PERIOD_LOCKED');
    }

    // ------------------------------------------------------------------------------------------------ call sites 1-5 (the employee's own inputs)

    public function test_call_site_1_attendance_is_locked_only_for_the_finalized_company(): void
    {
        $this->generate();
        $this->close($this->alpha);

        $this->refused($this->attendance($this->a));
        $this->attendance($this->b)->assertOk();
        $this->assertSame(1, $this->b->attendances()->count());
        $this->assertSame(0, $this->a->attendances()->count());
    }

    public function test_call_site_2_a_penalty_is_locked_only_for_the_finalized_company(): void
    {
        $this->generate();
        $this->close($this->alpha);

        $this->refused($this->penalty($this->a));
        $this->penalty($this->b)->assertStatus(201);
    }

    public function test_call_site_3_an_advance_is_locked_only_for_the_finalized_company(): void
    {
        $this->generate();
        $this->close($this->alpha);

        $this->refused($this->advance($this->a));
        $this->advance($this->b)->assertStatus(201);
    }

    public function test_call_site_4_recording_unpaid_leave_is_locked_only_for_the_finalized_company(): void
    {
        $this->generate();
        $this->close($this->alpha);

        $this->refused($this->leave($this->a));
        $this->leave($this->b)->assertStatus(201);
    }

    public function test_call_site_5_deciding_unpaid_leave_follows_the_same_company_lock(): void
    {
        // Both leaves are recorded while the month is open, then Alpha is approved: deciding Alpha's is refused, Bravo's is allowed.
        $this->generate();
        $this->leave($this->a)->assertStatus(201);
        $this->leave($this->b)->assertStatus(201);
        $leaveA = EmployeeLeave::query()->where('employee_id', $this->a->id)->firstOrFail();
        $leaveB = EmployeeLeave::query()->where('employee_id', $this->b->id)->firstOrFail();
        $this->close($this->alpha, false);

        $this->refused($this->as($this->super)->patchJson("/api/v1/admin/hr/employees/{$this->a->id}/leaves/{$leaveA->id}/approve", ['note' => 'Agreed']));
        $this->as($this->super)->patchJson("/api/v1/admin/hr/employees/{$this->b->id}/leaves/{$leaveB->id}/approve", ['note' => 'Agreed'])->assertOk();
        $this->assertSame('pending', $leaveA->fresh()->approval_status);
        $this->assertSame('approved', $leaveB->fresh()->approval_status);
    }

    // ------------------------------------------------------------------------------------------------ call sites 6-8 (a Temporary workday: the covered assignment)

    public function test_call_sites_6_7_8_workdays_follow_the_company_of_the_covered_assignment(): void
    {
        $this->generate();
        $this->close($this->alpha);
        $lock = new PayrollPeriodLock;

        // Record (6), verify (7) and void (8) all ask the lock about the COVERED ASSIGNMENT's company.
        foreach (['a position at Alpha' => [$this->aAssignment, true], 'a position at Bravo' => [$this->bAssignment, false]] as $what => [$assignment, $locked]) {
            try {
                $lock->assertDateEditable('2026-09-10', 'Workday 2026-09-10', null, $assignment->id);
                $this->assertFalse($locked, "{$what} must be locked");
            } catch (HttpResponseException $e) {
                $this->assertTrue($locked, "{$what} must stay editable");
                $this->assertSame('PAYROLL_PERIOD_LOCKED', $e->getResponse()->getData(true)['error_code']);
            }
        }

        $this->assertTrue($lock->isLockedForAssignment(self::P, $this->aAssignment->id), 'the replacement-change preview agrees');
        $this->assertFalse($lock->isLockedForAssignment(self::P, $this->bAssignment->id));

        // The three workday actions really pass the covered assignment (and no employee) to the lock.
        $record = file_get_contents(base_path('app/Actions/Hr/RecordReplacementWorkdaysAction.php'));
        $review = file_get_contents(base_path('app/Actions/Hr/ReviewReplacementWorkdayAction.php'));
        $this->assertStringContainsString('assertDateEditable($date, "Workday {$date}", null, $assignment->id)', $record);
        $this->assertSame(2, substr_count($review, "assertDateEditable(\$workday->work_date->toDateString(), 'This workday', null, (int) \$workday->work_assignment_id)"));
    }

    public function test_every_one_of_the_eight_call_sites_gives_the_lock_its_context(): void
    {
        $sites = [
            'Actions/Hr/MarkEmployeeAttendanceAction.php' => ["assertDateEditable(\$date, 'Attendance on this date', \$employee->id, null, InputOwnership::key(\$company))"],
            'Actions/Hr/RecordEmployeeAdvanceAction.php' => ['assertMonthEditable($data[\'payroll_period\'], \'The payroll month of this record\', $employee->id, InputOwnership::key($company))'],
            'Actions/Hr/RecordEmployeePenaltyAction.php' => ['assertMonthEditable($data[\'payroll_period\'], \'The payroll month of this record\', $employee->id, InputOwnership::key($company))'],
            'Actions/Hr/RecordEmployeeLeaveAction.php' => ['assertRangeEditable(', '$this->assertMonthsOpen($start, $end, $employee->id, InputOwnership::key($company))'],
            'Actions/Hr/DecideEmployeeLeaveAction.php' => ['(int) $leave->employee_id, $leave->client_company_id'],
            'Actions/Hr/RecordReplacementWorkdaysAction.php' => ['null, $assignment->id)'],
            'Actions/Hr/ReviewReplacementWorkdayAction.php' => ['null, (int) $workday->work_assignment_id)'],
        ];

        foreach ($sites as $file => $needles) {
            $source = file_get_contents(base_path('app/'.$file));

            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $source, "{$file} must pass the employee or assignment to the lock");
            }
        }

        // And no call site is left asking the month-wide question.
        foreach (array_keys($sites) as $file) {
            $this->assertDoesNotMatchRegularExpression("/assertDateEditable\\([^,]+, '[^']*'\\);/", file_get_contents(base_path('app/'.$file)), "{$file}: a month-wide lock call is left");
        }
    }

    // ------------------------------------------------------------------------------------------------ the whole month, a shared employee, a legacy run

    public function test_a_shared_employee_keeps_working_for_alpha_while_bravo_is_finalized(): void
    {
        // X works for both. Bravo is finalized, Alpha is still a draft: every input that belongs to ALPHA is still accepted, every input that belongs
        // to BRAVO is refused, and nothing is stored for Bravo.
        $this->generate();
        $this->close($this->bravo);

        $this->attendance($this->x, '2026-09-10', $this->alpha)->assertOk();
        $this->penalty($this->x, $this->alpha)->assertStatus(201);
        $this->advance($this->x, $this->alpha)->assertStatus(201);
        $this->leave($this->x, $this->alpha)->assertStatus(201);

        $this->refused($this->attendance($this->x, '2026-09-11', $this->bravo));
        $this->refused($this->penalty($this->x, $this->bravo));
        $this->refused($this->advance($this->x, $this->bravo));
        $this->refused($this->leave($this->x, $this->bravo, '2026-09-14', '2026-09-15'));

        $this->assertSame([$this->alpha->id], $this->x->attendances()->pluck('client_company_id')->all());
        $this->assertSame([$this->alpha->id], $this->x->penalties()->pluck('client_company_id')->all());
        $this->assertSame([$this->alpha->id], $this->x->advances()->pluck('client_company_id')->all());
        $this->assertSame([$this->alpha->id], $this->x->leaves()->pluck('client_company_id')->all());
    }

    public function test_a_shared_employee_keeps_working_for_bravo_while_alpha_is_finalized(): void
    {
        // The reverse direction: Alpha (the employee's first company) is finalized; Bravo is a draft and keeps accepting its own inputs.
        $this->generate();
        $this->close($this->alpha);

        $this->attendance($this->x, '2026-09-10', $this->bravo)->assertOk();
        $this->penalty($this->x, $this->bravo)->assertStatus(201);
        $this->advance($this->x, $this->bravo)->assertStatus(201);
        $this->leave($this->x, $this->bravo)->assertStatus(201);

        $this->refused($this->attendance($this->x, '2026-09-11', $this->alpha));
        $this->refused($this->penalty($this->x, $this->alpha));
        $this->refused($this->advance($this->x, $this->alpha));
        $this->refused($this->leave($this->x, $this->alpha, '2026-09-14', '2026-09-15'));
    }

    public function test_a_shared_employees_record_must_name_its_company_and_no_company_is_ever_guessed(): void
    {
        $this->generate();

        foreach ([$this->penalty($this->x), $this->advance($this->x), $this->attendance($this->x), $this->leave($this->x)] as $response) {
            $response->assertStatus(422)->assertJsonPath('error_code', 'COMPANY_REQUIRED');
        }

        // A company the employee does not work for is refused too (a single-company employee needs no choice at all).
        $this->penalty($this->x, $this->makeCompany('Elsewhere Co'))->assertStatus(422)->assertJsonPath('error_code', 'COMPANY_NOT_ASSIGNED');
        $this->penalty($this->a)->assertStatus(201);
        $this->assertSame($this->alpha->id, $this->a->penalties()->firstOrFail()->client_company_id, 'the only company the employee works for');
        $this->assertSame(0, $this->x->penalties()->count());
    }

    public function test_a_legacy_input_with_no_company_is_locked_when_any_company_the_employee_works_for_is_protected(): void
    {
        // An input from before company ownership names no company: judged by where the employee works, on the safe side.
        $this->generate();
        $this->close($this->alpha);
        $lock = new PayrollPeriodLock;

        $this->expectException(HttpResponseException::class);
        $lock->assertMonthEditable(self::P, 'Legacy record', $this->x->id);
    }

    public function test_a_month_whose_every_company_is_approved_is_locked_for_everyone_and_other_months_stay_open(): void
    {
        $this->generate();
        $this->close($this->alpha, false);
        $this->close($this->bravo);

        $this->refused($this->attendance($this->a));
        $this->refused($this->attendance($this->b));
        $this->attendance($this->a, '2026-10-05')->assertOk();
        $this->assertSame('approved', PayrollRun::query()->findOrFail($this->runId)->status, 'least advanced: Alpha is approved');
    }

    public function test_an_approved_company_is_as_protected_as_a_finalized_one(): void
    {
        $this->generate();
        $this->close($this->alpha, false);

        $this->refused($this->attendance($this->a));
        $this->attendance($this->b)->assertOk();
    }

    public function test_reopening_a_company_unlocks_its_inputs(): void
    {
        $this->generate();
        $this->close($this->alpha, false);
        $this->refused($this->attendance($this->a));

        $section = PayrollRunSection::query()->where('payroll_run_id', $this->runId)->where('client_company_id', $this->alpha->id)->firstOrFail();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$section->id}/reopen", ['reason' => 'A salary has to be corrected first'])->assertOk();
        $this->attendance($this->a)->assertOk();
    }

    public function test_a_run_without_sections_locks_the_whole_month_as_before(): void
    {
        $run = PayrollRun::query()->create(['period' => self::P, 'status' => 'approved', 'currency' => 'USD', 'totals' => ['item_count' => 1, 'net_cents' => 1]]);
        PayrollItem::query()->create(['payroll_run_id' => $run->id, 'period' => self::P, 'employee_id' => $this->a->id, 'work_assignment_id' => $this->aAssignment->id, 'employee_name' => 'x', 'employee_number' => 'EMP-1', 'eligible_days' => 30, 'days_in_month' => 30, 'gross_cents' => 1, 'net_cents' => 1, 'calc_snapshot' => []]);

        $this->refused($this->attendance($this->a));
        $this->refused($this->attendance($this->b));
        $this->refused($this->penalty($this->b));
    }
}
