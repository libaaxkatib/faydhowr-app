<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\TemporaryReplacement;
use App\Support\Hr\Payroll\PayrollRunCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner rules for Permanent / Temporary / Replacement assignments and the calendar-day pay of each:
 *  - a Replacement has NO salary of its own: its basis is the monthly salary of the Permanent employee whose position it takes over;
 *  - Temporary coverage leaves the Permanent assignment in place (it is deducted per verified day, never a second assignment);
 *  - pay is monthly salary / calendar days in the month x covered days, nobody is paid twice for a position-day, cent remainders
 *    go to the last line of the position, and a returning Permanent employee gets a NEW Permanent assignment with a salary HR confirms.
 */
class ReplacementAndTemporaryRulesTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $company;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
        $this->company = $this->makeCompany('Acme Co');
        [, $this->admin] = $this->superAdminToken();
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

    private function companyUrl(string $tail): string
    {
        return "/api/v1/admin/hr/client-companies/{$this->company->id}/employees".$tail;
    }

    private function replace(EmployeeWorkAssignment $original, Employee $replacement, array $over = [])
    {
        return $this->as($this->admin)->postJson($this->companyUrl("/{$original->id}/replace"), array_replace([
            'replacement_employee_id' => $replacement->id, 'replacement_date' => '2026-09-16', 'assignment_type' => 'replacement',
        ], $over));
    }

    private function returnTo(EmployeeWorkAssignment $replacement, string $date, array $over = [])
    {
        return $this->as($this->admin)->postJson($this->companyUrl("/{$replacement->id}/return"), array_replace([
            'return_date' => $date, 'salary_amount' => 300, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ], $over));
    }

    private function generate(string $period = '2026-09')
    {
        return $this->as($this->admin)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated();
    }

    /** @return array<int, PayrollItem> payroll lines of the month keyed by assignment id */
    private function lines(string $period = '2026-09'): array
    {
        return PayrollItem::query()->where('period', $period)->get()->keyBy('work_assignment_id')->all();
    }

    // ------------------------------------------------------------------ Replacement: salary basis

    public function test_a_replacement_has_no_salary_of_its_own_its_basis_is_the_replaced_permanent_employees_salary(): void
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        // The stand-in once worked elsewhere on a different salary: that salary must play no part.
        $standIn = $this->makeEmployee();
        $old = $this->makeAssignment($standIn, $this->makeCompany('Earlier Co'), null, 500, '2026-01-01');
        $old->update(['status' => 'ended', 'end_date' => '2026-06-30']);

        $id = $this->replace($original, $standIn)->assertOk()->json('data.replacement.id');

        $new = EmployeeWorkAssignment::query()->findOrFail($id);
        $this->assertSame('replacement', $new->assignment_type->value);
        $this->assertSame($original->id, $new->replaces_assignment_id, 'the relationship to the original Permanent assignment is recorded');
        $this->assertSame('300.00', (string) $new->salary_amount, "the salary basis is the original's 300, not the stand-in's earlier 500");
        $this->assertSame('USD', $new->salary_currency);
        $this->assertSame('monthly', $new->salary_frequency->value);

        $history = $new->salaryHistories()->get();
        $this->assertCount(1, $history);
        $this->assertStringContainsString("Inherited from Permanent assignment #{$original->id}", (string) $history[0]->reason, 'the basis is written on the salary history so the calculation can be reproduced');
        $log = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $id)->where('action', 'create')->firstOrFail();
        $this->assertSame($original->id, $log->metadata['salary_basis']['assignment_id']);
        $this->assertSame('300.00', $log->metadata['salary_basis']['amount']);

        $this->assertSame(1, $original->fresh()->salaryHistories()->count(), "the original Permanent's salary history is untouched");
        $this->assertSame('300.00', (string) $original->fresh()->salary_amount);
    }

    public function test_300_dollars_over_30_days_for_15_days_pays_150_and_the_original_is_paid_for_its_own_15_days(): void
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $replacementId = $this->replace($original, $this->makeEmployee(), ['replacement_date' => '2026-09-16'])->assertOk()->json('data.replacement.id');

        $this->generate();
        $lines = $this->lines();

        $this->assertSame(15000, (int) $lines[$replacementId]->gross_cents, '300 / 30 x 15 = 150.00 for the Replacement');
        $this->assertSame(15, $lines[$replacementId]->eligible_days);
        $this->assertSame(15000, (int) $lines[$original->id]->gross_cents, 'the replaced Permanent keeps only the days before');
        $this->assertSame(30000, (int) PayrollItem::query()->where('period', '2026-09')->sum('gross_cents'), 'the position costs 300.00, not 300.00 twice');
    }

    public function test_a_typed_in_salary_for_a_replacement_is_refused_and_never_overrides_the_original_salary(): void
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $standIn = $this->makeEmployee();

        $this->replace($original, $standIn, ['salary_amount' => 240, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'REPLACEMENT_SALARY_IS_INHERITED');

        // The general assignment endpoint obeys the same rule.
        $original->update(['status' => 'ended', 'end_date' => '2026-09-15']);
        $this->as($this->admin)->postJson("/api/v1/admin/hr/employees/{$standIn->id}/work-assignments", [
            'client_company_id' => $this->company->id, 'replaces_assignment_id' => $original->id, 'assignment_type' => 'replacement',
            'start_date' => '2026-09-16', 'salary_amount' => 240, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'REPLACEMENT_SALARY_IS_INHERITED');

        $this->assertSame(0, EmployeeWorkAssignment::query()->where('employee_id', $standIn->id)->count(), 'nothing was created');
    }

    public function test_a_later_change_of_the_original_salary_does_not_rewrite_the_replacements_recorded_basis(): void
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $replacementId = $this->replace($original, $this->makeEmployee())->assertOk()->json('data.replacement.id');

        $this->as($this->admin)->putJson("/api/v1/admin/hr/work-assignments/{$original->id}", ['salary_amount' => 400, 'salary_effective_from' => '2026-11-01'])->assertOk();

        $this->assertSame('300.00', (string) EmployeeWorkAssignment::query()->findOrFail($replacementId)->salary_amount, 'the replacement keeps the basis recorded when it started');
    }

    public function test_the_replaced_permanent_and_the_replacement_are_never_both_paid_for_the_same_days(): void
    {
        // Overlapping dates entered directly (the guards would refuse them): the Replacement holds the position from the 16th.
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $replacement = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-09-16');
        $replacement->update(['assignment_type' => 'replacement', 'replaces_assignment_id' => $original->id]);

        $this->generate();
        $lines = $this->lines();

        $this->assertSame(15, $lines[$original->id]->eligible_days, 'the days the Replacement holds are not paid to the original');
        $this->assertSame(15, $lines[$replacement->id]->eligible_days);
        $this->assertSame(30000, (int) PayrollItem::query()->where('period', '2026-09')->sum('gross_cents'));
        $this->assertSame(15, $lines[$original->id]->calc_snapshot['excluded_replacement_days']);
        $this->assertTrue(collect(PayrollRun::query()->firstOrFail()->totals['warnings'])->contains(fn ($w) => str_contains($w, 'nobody is paid twice')));
    }

    // ------------------------------------------------------------------ Temporary

    private function cover(EmployeeWorkAssignment $assignment, array $verifiedDays, array $recordedDays = [], bool $approved = true): TemporaryReplacement
    {
        $temp = $this->makeEmployee('active', 'temporary');
        // The cover's ACTUAL coverage period (inclusive) is what counts (docs/HR_PAYROLL_RULES.md U8): from the first to the last day given here.
        // The recorded/verified workdays below are attendance records and never change the amount.
        $all = array_merge($verifiedDays, $recordedDays);
        $replacement = TemporaryReplacement::query()->create([
            'work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id,
            'start_date' => sprintf('2026-09-%02d', min($all)), 'end_date' => sprintf('2026-09-%02d', max($all)), 'currency' => 'USD', 'status' => 'active', 'approved_at' => $approved ? now() : null,
        ]);

        foreach ([[$verifiedDays, 'verified'], [$recordedDays, 'recorded']] as [$days, $status]) {
            foreach ($days as $day) {
                $replacement->workdays()->create([
                    'work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id,
                    'work_date' => sprintf('2026-09-%02d', $day), 'status' => $status,
                ]);
            }
        }

        return $replacement;
    }

    public function test_temporary_coverage_leaves_the_permanent_assignment_in_place_and_is_paid_for_its_coverage_period(): void
    {
        $permanent = $this->makeEmployee();
        $assignment = $this->makeAssignment($permanent, $this->company, null, 300, '2026-01-01');
        $cover = $this->cover($assignment, [3, 4, 5]);

        $this->generate();
        $line = $this->lines()[$assignment->id];

        $this->assertSame('active', $assignment->fresh()->status->value, 'the Permanent assignment continues');
        $this->assertSame('permanent', $assignment->fresh()->assignment_type->value);
        $this->assertNull($assignment->fresh()->end_date);
        $this->assertSame(1, EmployeeWorkAssignment::query()->count(), 'no second assignment is created for Temporary coverage');
        $this->assertSame(30000, (int) $line->gross_cents);
        $this->assertSame(3000, (int) $line->replacement_deduction_cents, '3 covered days (3-5 September, both ends inclusive) x (300 / 30) come off the covered Permanent employee');
        $this->assertSame(27000, (int) $line->net_cents);
        $this->assertSame(1, PayrollItem::query()->count(), 'the Temporary worker has no salary line of their own: they are paid the deducted amount');
        $this->assertSame($cover->replacement_employee_id, $cover->fresh()->replacement_employee_id);

        // Return is for Replacements only: nothing is created for a Permanent who never left.
        $this->returnTo($assignment, '2026-09-20')->assertUnprocessable()->assertJsonPath('error_code', 'RETURN_NOT_A_REPLACEMENT');
        $this->assertSame(1, EmployeeWorkAssignment::query()->count());
    }

    public function test_temporary_and_replacement_stay_separate_types_with_separate_workflows(): void
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $this->cover($original, [3]);
        $temporaryOnly = EmployeeWorkAssignment::query()->where('assignment_type', 'replacement')->count();
        $this->assertSame(0, $temporaryOnly, 'Temporary coverage never creates a Replacement assignment');

        $replacementId = $this->replace($original, $this->makeEmployee())->assertOk()->json('data.replacement.id');
        $this->assertSame('replacement', EmployeeWorkAssignment::query()->findOrFail($replacementId)->assignment_type->value);
        $this->assertSame(1, TemporaryReplacement::query()->count(), 'and a Replacement creates no Temporary coverage record');

        // A Temporary employee holding a monthly assignment is not paid a salary line: it is reported, not guessed.
        $temp = $this->makeEmployee('active', 'temporary');
        $this->makeAssignment($temp, $this->makeCompany('Other Co'), null, 200, '2026-01-01');
        $this->generate();
        $run = PayrollRun::query()->firstOrFail();
        $this->assertTrue(collect($run->totals['warnings'])->contains(fn ($w) => str_contains($w, 'belongs to a Temporary employee')));
        $this->assertSame(0, PayrollItem::query()->where('employee_id', $temp->id)->count());
    }

    public function test_a_temporary_worker_registered_as_a_replacement_triggers_a_warning_that_hr_must_decide(): void
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $temp = $this->makeEmployee('active', 'temporary');

        $this->replace($original, $temp)->assertUnprocessable()->assertJsonPath('error_code', 'TEMPORARY_REGISTERED_AS_REPLACEMENT');
        $this->assertSame('active', $original->fresh()->status->value, 'nothing was converted or ended without the decision');
        $this->assertSame(0, EmployeeWorkAssignment::query()->where('employee_id', $temp->id)->count());

        // HR decides to proceed: only then is it saved - as a Replacement, never silently converted to anything else.
        $id = $this->replace($original, $temp, ['acknowledge_temporary_as_replacement' => true])->assertOk()->json('data.replacement.id');
        $this->assertSame('replacement', EmployeeWorkAssignment::query()->findOrFail($id)->assignment_type->value);
    }

    public function test_the_coverage_period_decides_the_deduction_not_which_days_attendance_was_verified(): void
    {
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $this->cover($assignment, [3], [4, 5]); // the cover ran from 3 to 5 September; only day 3 was verified by HR, 4 and 5 were only recorded

        $this->generate();
        $line = $this->lines()[$assignment->id];

        // U8: the actual coverage period (inclusive) is used for the Temporary's entitlement and for the deduction. Attendance is a record only.
        $this->assertSame(3000, (int) $line->replacement_deduction_cents, 'all three days of the cover period are deducted, whatever was verified');
    }

    public function test_the_same_absence_is_never_deducted_twice(): void
    {
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->company, null, 300, '2026-01-01');
        EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => '2026-09-05', 'status' => 'absent']);
        $this->cover($assignment, [5]); // the same day is already deducted as Temporary cover

        $this->generate();
        $line = $this->lines()[$assignment->id];

        $this->assertSame(1000, (int) $line->replacement_deduction_cents);
        $this->assertSame(0, (int) $line->other_deduction_cents, 'the absent day is already paid for by the cover: no second deduction');
        $this->assertSame(29000, (int) $line->net_cents);

        // A plain absence is one daily rate, once.
        EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => '2026-09-09', 'status' => 'absent']);
        $this->generate();
        $this->assertSame(1000, (int) $this->lines()[$assignment->id]->other_deduction_cents);
    }

    // ------------------------------------------------------------------ calendar-day pay

    public function test_a_gap_between_the_original_and_the_replacement_is_unpaid(): void
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $replacementId = $this->replace($original, $this->makeEmployee(), ['original_last_day' => '2026-09-10', 'replacement_date' => '2026-09-16'])->assertOk()->json('data.replacement.id');

        $this->generate();
        $lines = $this->lines();

        $this->assertSame(10000, (int) $lines[$original->id]->gross_cents, '10 days');
        $this->assertSame(15000, (int) $lines[$replacementId]->gross_cents, '15 days');
        $this->assertSame(25000, (int) PayrollItem::query()->sum('gross_cents'), 'the 5 uncovered days (11th to 15th) are paid to nobody');
    }

    public function test_a_full_month_assignment_pays_the_full_salary_and_only_marked_absences_reduce_it(): void
    {
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $this->company, null, 300, '2026-01-01');

        $this->generate();
        $this->assertSame(30000, (int) $this->lines()[$assignment->id]->net_cents, 'no attendance marks at all: the full month is paid');

        EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => '2026-09-05', 'status' => 'absent']);
        $this->generate();
        $this->assertSame(29000, (int) $this->lines()[$assignment->id]->net_cents, 'one confirmed absence costs one daily rate (300 / 30)');
    }

    public function test_month_lengths_of_28_29_30_and_31_days_are_used(): void
    {
        $calculator = new PayrollRunCalculator;
        $cases = [
            ['2027-02', 280, '2027-02-15', 14000, 14],  // 28 days: 14 days x 10.00
            ['2028-02', 290, '2028-02-16', 14000, 14],  // leap year, 29 days: 14 days x 10.00
            ['2026-09', 300, '2026-09-11', 20000, 20],  // 30 days
            ['2026-10', 300, '2026-10-11', 20323, 21],  // 31 days: 21 x 300 / 31 = 203.23
        ];

        foreach ($cases as [$period, $salary, $start, $expectedCents, $expectedDays]) {
            $employee = $this->makeEmployee();
            $this->makeAssignment($employee, $this->makeCompany("Co {$period}"), null, $salary, $start);
            $item = collect($calculator->calculate($period, [$employee->id])['items'])->sole();

            $this->assertSame($expectedDays, $item['eligible_days'], $period);
            $this->assertSame($expectedCents, $item['gross_cents'], $period);
        }
    }

    public function test_cent_remainders_go_to_the_last_line_of_the_position_so_the_total_reconciles(): void
    {
        // Three people hold one 100.00 position for 10 days each of a 30-day month: 33.33 + 33.33 + 33.34 = 100.00.
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 100, '2026-09-01');
        $replacementId = $this->replace($original, $this->makeEmployee(), ['replacement_date' => '2026-09-11'])->assertOk()->json('data.replacement.id');
        $returnedId = $this->returnTo(EmployeeWorkAssignment::query()->findOrFail($replacementId), '2026-09-21', ['salary_amount' => 100])->assertOk()->json('data.returned.id');

        $this->generate();
        $lines = $this->lines();

        $this->assertSame(3333, (int) $lines[$original->id]->gross_cents);
        $this->assertSame(3333, (int) $lines[$replacementId]->gross_cents);
        $this->assertSame(3334, (int) $lines[$returnedId]->gross_cents, 'the remainder goes to the last line of the position (latest start date)');
        $this->assertSame(10000, (int) PayrollItem::query()->where('period', '2026-09')->sum('gross_cents'), 'the three lines add up to exactly the position amount');
        $this->assertSame(1, $lines[$returnedId]->calc_snapshot['rounding_remainder_cents']);
        $this->assertSame(0, $lines[$original->id]->calc_snapshot['rounding_remainder_cents']);
    }

    // ------------------------------------------------------------------ returning Permanent employee

    public function test_a_returning_permanent_employee_gets_a_new_permanent_assignment_and_all_history_is_kept(): void
    {
        $originalEmployee = $this->makeEmployee();
        $original = $this->makeAssignment($originalEmployee, $this->company, null, 300, '2026-01-01');
        $replacementId = $this->replace($original, $this->makeEmployee(), ['replacement_date' => '2026-09-10'])->assertOk()->json('data.replacement.id');

        $returnedId = $this->returnTo(EmployeeWorkAssignment::query()->findOrFail($replacementId), '2026-09-21')->assertOk()->json('data.returned.id');

        $returned = EmployeeWorkAssignment::query()->findOrFail($returnedId);
        $this->assertSame('permanent', $returned->assignment_type->value);
        $this->assertSame($originalEmployee->id, $returned->employee_id);
        $this->assertSame($replacementId, $returned->replaces_assignment_id);
        $this->assertSame('2026-09-21', $returned->start_date->toDateString());

        $replacement = EmployeeWorkAssignment::query()->findOrFail($replacementId);
        $this->assertSame('2026-09-20', $replacement->end_date->toDateString(), "the Replacement's final covered day is the day before the return");
        $this->assertSame('ended', $replacement->status->value);

        $this->assertSame(3, EmployeeWorkAssignment::query()->count(), 'original, Replacement and returned assignment are all kept');
        $this->assertSame('2026-09-09', $original->fresh()->end_date->toDateString());
        $this->assertSame(1, $original->fresh()->salaryHistories()->count(), 'the original Permanent salary history is not overwritten');
    }

    public function test_the_previous_salary_is_proposed_but_hr_must_confirm_or_edit_it_before_saving(): void
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $replacementId = $this->replace($original, $this->makeEmployee(), ['replacement_date' => '2026-09-10'])->assertOk()->json('data.replacement.id');

        // The list gives the screen the previous salary to show as the default.
        $row = collect($this->as($this->admin)->getJson($this->companyUrl('').'?period=2026-09')->json('data.employees'))->firstWhere('assignment_id', $replacementId);
        $this->assertSame('300.00', $row['replaces']['salary']['amount']);
        $this->assertSame($original->employee_id, $row['replaces']['employee_id']);

        // Without a confirmed salary nothing is saved.
        $this->as($this->admin)->postJson($this->companyUrl("/{$replacementId}/return"), ['return_date' => '2026-09-21'])
            ->assertUnprocessable()->assertJsonValidationErrors(['salary_amount', 'salary_currency', 'salary_frequency'], 'errors');
        $this->assertSame(2, EmployeeWorkAssignment::query()->count());
        $this->assertSame('active', EmployeeWorkAssignment::query()->findOrFail($replacementId)->status->value);

        // HR edits the proposal: the new Permanent assignment carries the edited salary and the audit keeps both values.
        $returnedId = $this->returnTo(EmployeeWorkAssignment::query()->findOrFail($replacementId), '2026-09-21', ['salary_amount' => 320])->assertOk()->json('data.returned.id');
        $this->assertSame('320.00', (string) EmployeeWorkAssignment::query()->findOrFail($returnedId)->salary_amount);
        $log = AuditLog::query()->where('entity_id', $replacementId)->get()->first(fn ($l) => ($l->metadata['event'] ?? null) === 'returned');
        $this->assertSame('300.00', $log->metadata['previous_salary']['amount']);
        $this->assertSame('320.00', $log->metadata['confirmed_salary']['amount']);
    }
}
