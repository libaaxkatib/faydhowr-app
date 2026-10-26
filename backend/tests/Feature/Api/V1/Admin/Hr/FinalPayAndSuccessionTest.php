<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\TemporaryReplacement;
use App\Support\Hr\PositionSuccession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Approved policy: Fayadhowr operates every calendar day, so there are no
 * excluded weekend days. A leaver is paid for every calendar day up to and
 * including the separation date; a Permanent successor from their own start
 * date; the denominator is the month's real number of days. No day is paid
 * twice, gaps are reported, and Temporary replacement stays a separate
 * workflow that never charges a day the covered employee was not payable.
 */
class FinalPayAndSuccessionTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * A leaver with an open Permanent assignment needs a replacement choice (docs/HR_PAYROLL_RULES.md N1). By default a Waiting List person is
     * chosen for each open Permanent assignment; pass `$successors` to choose someone specific.
     *
     * @param  list<array<string, mixed>>|null  $successors
     */
    private function separate(string $token, Employee $employee, string $date, ?array $successors = null)
    {
        return $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", [
            'reason' => 'resigned', 'separation_date' => $date, 'rehire_eligible' => true, 'notes' => 'HR confirmed',
            'successors' => $successors ?? $this->successorsFor($employee),
        ]);
    }

    /** A predecessor whose last paid day is already recorded, however it got there (data from before the replacement rule): no successor is created. */
    private function closeDirectly(EmployeeWorkAssignment $assignment, string $lastDay): void
    {
        $assignment->update(['end_date' => $lastDay, 'status' => 'ended']);
    }

    private function generate(string $token, string $period = '2026-09')
    {
        return $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period]);
    }

    private function takeOver(string $token, Employee $successor, ClientCompany $company, EmployeeWorkAssignment $predecessor, string $start, int $dollars = 300)
    {
        return $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$successor->id}/work-assignments", [
            'client_company_id' => $company->id, 'replaces_assignment_id' => $predecessor->id,
            'start_date' => $start, 'salary_amount' => $dollars, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ]);
    }

    /** @return array{0: Employee, 1: EmployeeWorkAssignment, 2: ClientCompany} */
    private function leaver(int $dollars = 300): array
    {
        $employee = $this->makeEmployee('active', 'permanent');
        $company = $this->makeCompany(fake()->unique()->company());

        return [$employee, $this->makeAssignment($employee, $company, null, $dollars, '2026-01-01'), $company];
    }

    private function lineFor(string $token, int $runId, Employee $employee): array
    {
        $items = $this->withToken($token)->getJson("/api/v1/admin/hr/payroll-runs/{$runId}")->json('data.items');

        return collect($items)->firstWhere('employee_id', $employee->id);
    }

    // ------------------------------------------------------------ final pay

    public function test_final_pay_counts_every_calendar_day_up_to_the_separation_date_including_weekends(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->leaver(300);
        $this->separate($token, $employee, '2026-09-10')->assertOk(); // 2026-09-05/06 are Sat/Sun

        $response = $this->generate($token)->assertCreated();

        // The leaver's own line (the chosen replacement has a line of its own from 2026-09-11).
        $this->assertSame('100.00', $this->lineFor($token, $response->json('data.id'), $employee)['gross']); // 300 x 10 / 30, weekends included
        $snapshot = PayrollItem::query()->where('employee_id', $employee->id)->firstOrFail()->calc_snapshot;
        $this->assertSame('2026-09-10', $snapshot['final_pay']['last_paid_day']);
        $this->assertSame(10, $snapshot['final_pay']['paid_calendar_days']);
        $this->assertSame(30, $snapshot['final_pay']['days_in_month']);
        $this->assertSame('2026-09-10', $assignment->fresh()->end_date->toDateString());
    }

    public function test_a_separation_that_falls_on_a_sunday_still_pays_through_that_sunday(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee] = $this->leaver(300);
        $this->separate($token, $employee, '2026-09-06')->assertOk(); // Sunday

        $run = $this->generate($token)->assertCreated();
        $this->assertSame('60.00', $this->lineFor($token, $run->json('data.id'), $employee)['gross']); // 6 days
    }

    public function test_the_denominator_is_the_months_real_length(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee] = $this->leaver(300);
        $this->separate($token, $employee, '2026-10-10')->assertOk();

        $run = $this->generate($token, '2026-10')->assertCreated();
        $this->assertSame('96.77', $this->lineFor($token, $run->json('data.id'), $employee)['gross']); // 300 x 10 / 31
    }

    public function test_a_month_with_no_separation_has_no_final_pay_marker_and_pays_the_full_month(): void
    {
        [, $token] = $this->superAdminToken();
        $this->leaver(300);

        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.gross', '300.00');
        $this->assertNull(PayrollItem::query()->firstOrFail()->calc_snapshot['final_pay']);
    }

    // ------------------------------------------------------------ permanent succession

    public function test_leaver_plus_successor_pay_exactly_one_month_with_no_gap_or_double_day(): void
    {
        [, $token] = $this->superAdminToken();
        [$leaver, $old, $company] = $this->leaver(300);
        // The separation itself appoints the replacement (a Waiting List person on 300 a month) from the day after the last paid day.
        $choice = $this->waitingSuccessor(300);
        $successor = Employee::query()->findOrFail($choice['employee_id']);
        $this->separate($token, $leaver, '2026-09-10', [['assignment_id' => $old->id] + $choice])->assertOk();

        $new = EmployeeWorkAssignment::query()->where('employee_id', $successor->id)->firstOrFail();
        $this->assertSame($old->id, $new->replaces_assignment_id);
        $this->assertSame('2026-09-11', $new->start_date->toDateString());
        $this->assertSame(0, PositionSuccession::gapDays($old->fresh()->end_date, $new->start_date));

        $run = $this->generate($token)->assertCreated();

        $this->assertSame('300.00', $run->json('data.totals.gross')); // 100.00 + 200.00
        $this->assertSame('0.00', $run->json('data.totals.replacement_deductions'));
        $this->assertSame('0.00', $run->json('data.totals.other_deductions'));
        $this->assertSame('100.00', $this->lineFor($token, $run->json('data.id'), $leaver)['gross']);
        $this->assertSame('200.00', $this->lineFor($token, $run->json('data.id'), $successor)['gross']);
        $this->assertSame([], $run->json('data.warnings'));
        $this->assertSame($old->id, PayrollItem::query()->where('employee_id', $successor->id)->firstOrFail()->calc_snapshot['succession']['replaces_assignment_id']);
    }

    public function test_a_successor_starting_on_or_before_the_leavers_last_paid_day_is_refused(): void
    {
        [, $token] = $this->superAdminToken();
        [$leaver, $old, $company] = $this->leaver();
        $successor = $this->makeEmployee('active', 'permanent');
        $this->closeDirectly($old, '2026-09-10');

        $this->takeOver($token, $successor, $company, $old->fresh(), '2026-09-10')
            ->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSION_OVERLAP');
        $this->takeOver($token, $successor, $company, $old->fresh(), '2026-09-05')
            ->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSION_OVERLAP');
        $this->assertSame(0, EmployeeWorkAssignment::query()->where('employee_id', $successor->id)->count());
    }

    public function test_a_gap_is_allowed_but_reported_and_nobody_is_paid_for_it(): void
    {
        [, $token] = $this->superAdminToken();
        [$leaver, $old, $company] = $this->leaver(300);
        $successor = $this->makeEmployee('active', 'permanent');
        $this->closeDirectly($old, '2026-09-10');

        $this->takeOver($token, $successor, $company, $old->fresh(), '2026-09-14')
            ->assertCreated()->assertJsonPath('data.succession_gap_days', 3);

        $run = $this->generate($token)->assertCreated();

        $this->assertSame('270.00', $run->json('data.totals.gross')); // 3 uncovered days are paid to nobody
        $this->assertSame('100.00', $this->lineFor($token, $run->json('data.id'), $leaver)['gross']);
        $this->assertSame('170.00', $this->lineFor($token, $run->json('data.id'), $successor)['gross']); // 17 days: 14..30
        $this->assertTrue(collect($run->json('data.warnings'))->contains(fn ($w) => str_contains($w, '3 day(s) are uncovered')));

        $audit = DB::table('audit_logs')->where('entity_type', EmployeeWorkAssignment::class)->where('description', 'like', '%takes over%')->first();
        $meta = json_decode($audit->metadata, true);
        $this->assertSame(3, $meta['uncovered_gap_days']);
        $this->assertSame('2026-09-10', $meta['predecessor_last_paid_day']);
    }

    public function test_succession_needs_a_closed_unreplaced_same_company_predecessor(): void
    {
        [, $token] = $this->superAdminToken();
        [$leaver, $old, $company] = $this->leaver();
        $first = $this->makeEmployee('active', 'permanent');
        $second = $this->makeEmployee('active', 'permanent');

        $this->takeOver($token, $first, $company, $old, '2026-09-11')->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSION_PREDECESSOR_STILL_OPEN');

        $this->closeDirectly($old, '2026-09-10');
        $this->takeOver($token, $first, $company, $old->fresh(), '2026-09-11')->assertCreated();
        $this->takeOver($token, $second, $company, $old->fresh(), '2026-09-12')->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSION_ALREADY_REPLACED');

        [, $other, $otherCompany] = $this->leaver();
        $other->update(['end_date' => '2026-09-10']);
        $this->takeOver($token, $second, $company, $other->fresh(), '2026-09-12')->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSION_DIFFERENT_COMPANY');
        $this->assertNotSame($company->id, $otherCompany->id);
    }

    public function test_separation_is_refused_when_it_would_overlap_an_existing_successor(): void
    {
        [, $token] = $this->superAdminToken();
        [$leaver, $old, $company] = $this->leaver();
        $successor = $this->makeEmployee('active', 'permanent');
        $new = $this->makeAssignment($successor, $company, null, 300, '2026-09-08');
        $new->update(['replaces_assignment_id' => $old->id]);

        $this->separate($token, $leaver, '2026-09-10', $this->successorsFor($leaver))->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSION_OVERLAP');
        $this->assertSame('active', $leaver->fresh()->status->value, 'the whole separation is rolled back');
        $this->assertNull($old->fresh()->end_date);
        $this->assertSame(0, DB::table('employee_separations')->where('employee_id', $leaver->id)->count());

        $this->separate($token, $leaver, '2026-09-07')->assertOk();
        $this->assertSame('2026-09-07', $old->fresh()->end_date->toDateString());
    }

    public function test_editing_dates_cannot_create_an_overlap(): void
    {
        [, $token] = $this->superAdminToken();
        [$leaver, $old, $company] = $this->leaver();
        $successor = $this->makeEmployee('active', 'permanent');
        $this->closeDirectly($old, '2026-09-10');
        $new = $this->takeOver($token, $successor, $company, $old->fresh(), '2026-09-11')->assertCreated()->json('data.id');

        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$old->id}", ['end_date' => '2026-09-15'])
            ->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSION_OVERLAP');
        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$new}", ['start_date' => '2026-09-09'])
            ->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSION_OVERLAP');
        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$new}", ['start_date' => '2026-09-13'])->assertOk();

        $this->assertSame('2026-09-10', $old->fresh()->end_date->toDateString());
    }

    public function test_ending_an_assignment_manually_cannot_overlap_its_successor_either(): void
    {
        [, $token] = $this->superAdminToken();
        [$leaver, $old, $company] = $this->leaver();
        $successor = $this->makeEmployee('active', 'permanent');
        $this->closeDirectly($old, '2026-09-10');
        $this->takeOver($token, $successor, $company, $old->fresh(), '2026-09-11')->assertCreated();

        $this->withToken($token)->patchJson("/api/v1/admin/hr/work-assignments/{$old->id}/end", ['end_date' => '2026-09-12'])
            ->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSION_OVERLAP');
        $this->assertSame('2026-09-10', $old->fresh()->end_date->toDateString());
    }

    public function test_an_overlap_that_got_in_by_other_means_is_flagged_loudly_in_payroll(): void
    {
        [, $token] = $this->superAdminToken();
        [, $old, $company] = $this->leaver();
        $old->update(['end_date' => '2026-09-10', 'status' => 'ended']);
        $successor = $this->makeEmployee('active', 'permanent');
        $this->makeAssignment($successor, $company, null, 300, '2026-09-08')->update(['replaces_assignment_id' => $old->id]);

        $run = $this->generate($token)->assertCreated();

        $this->assertTrue(collect($run->json('data.warnings'))->contains(fn ($w) => str_contains($w, 'DOUBLE PAY RISK')));
    }

    public function test_a_permanent_successor_creates_no_replacement_deduction_for_the_leaver(): void
    {
        [, $token] = $this->superAdminToken();
        [$leaver, $old, $company] = $this->leaver(300);
        $choice = $this->waitingSuccessor(300);
        $this->separate($token, $leaver, '2026-09-10', [['assignment_id' => $old->id] + $choice])->assertOk();

        $this->assertSame(0, TemporaryReplacement::query()->count());
        $line = $this->lineFor($token, $this->generate($token)->json('data.id'), $leaver);
        $this->assertSame([], $line['deductions']);
    }

    // ------------------------------------------------------------ temporary replacement boundaries

    private function coverDays(EmployeeWorkAssignment $assignment, array $days, ?string $end = null): TemporaryReplacement
    {
        $temp = $this->makeEmployee('active', 'temporary');
        $replacement = TemporaryReplacement::query()->create([
            'work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id,
            'start_date' => '2026-09-01', 'end_date' => $end, 'currency' => 'USD', 'status' => 'active', 'approved_at' => now(),
        ]);

        foreach ($days as $day) {
            $replacement->workdays()->create([
                'work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id,
                'work_date' => sprintf('2026-09-%02d', $day), 'status' => 'verified',
            ]);
        }

        return $replacement;
    }

    public function test_temporary_days_after_the_covered_employee_left_are_not_charged_to_them(): void
    {
        [, $token] = $this->superAdminToken();
        [$leaver, $assignment] = $this->leaver(300);
        $assignment->update(['end_date' => '2026-09-10', 'status' => 'ended']);
        $this->coverDays($assignment, range(8, 12)); // 8,9,10 inside; 11,12 after the last paid day

        $run = $this->generate($token)->assertCreated();

        $this->assertSame('30.00', $run->json('data.totals.replacement_deductions')); // 3 days x 10.00
        $this->assertSame('70.00', $run->json('data.totals.net')); // 100.00 - 30.00
        $this->assertTrue(collect($run->json('data.warnings'))->contains(fn ($w) => str_contains($w, '2026-09-11') && str_contains($w, 'NOT charged')));
        $detail = $this->lineFor($token, $run->json('data.id'), $leaver)['deductions'][0]['detail'];
        $this->assertSame(['2026-09-11', '2026-09-12'], $detail['not_chargeable']['outside_assignment']);
        $this->assertSame(3, $detail['verified_days']);
    }

    public function test_temporary_days_already_unpaid_leave_are_not_deducted_a_second_time(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->leaver(300);
        EmployeeLeave::query()->create([
            'employee_id' => $employee->id, 'leave_type' => 'unpaid', 'approval_status' => 'approved',
            'start_date' => '2026-09-05', 'end_date' => '2026-09-06',
            'recorded_by' => Admin::factory()->create()->id, 'decided_by' => Admin::factory()->create()->id, 'decided_at' => now(),
        ]);
        $this->coverDays($assignment, [4, 5, 6, 7]);

        $run = $this->generate($token)->assertCreated();

        $this->assertSame('20.00', $run->json('data.totals.replacement_deductions')); // days 4 and 7 only
        $this->assertSame('20.00', $run->json('data.totals.other_deductions')); // unpaid leave, days 5-6
        $this->assertSame('260.00', $run->json('data.totals.net')); // not 240.00
        $this->assertTrue(collect($run->json('data.warnings'))->contains(fn ($w) => str_contains($w, 'already unpaid leave')));
    }

    public function test_pending_unpaid_leave_does_not_exempt_a_replacement_day(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->leaver(300);
        EmployeeLeave::query()->create([
            'employee_id' => $employee->id, 'leave_type' => 'unpaid', 'approval_status' => 'pending',
            'start_date' => '2026-09-05', 'end_date' => '2026-09-05', 'recorded_by' => Admin::factory()->create()->id,
        ]);
        $this->coverDays($assignment, [5]);

        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.replacement_deductions', '10.00');
    }

    public function test_verified_temporary_days_for_an_assignment_that_ended_before_the_month_are_reported(): void
    {
        [, $token] = $this->superAdminToken();
        [, $assignment] = $this->leaver(300);
        $assignment->update(['end_date' => '2026-08-31', 'status' => 'ended']);
        $this->coverDays($assignment, [2, 3]);

        $run = $this->generate($token)->assertCreated();

        $this->assertTrue(collect($run->json('data.warnings'))->contains(fn ($w) => str_contains($w, '2 verified Temporary replacement day(s)') && str_contains($w, 'no payroll line')));
    }

    public function test_the_same_replacement_cost_is_charged_only_once_across_two_runs_of_the_same_month(): void
    {
        [, $token] = $this->superAdminToken();
        [, $assignment] = $this->leaver(300);
        $this->coverDays($assignment, range(1, 5));

        $first = $this->generate($token)->assertCreated();
        $this->assertSame('50.00', $first->json('data.totals.replacement_deductions'));

        // Regenerating the draft recomputes its lines; it must never stack a second charge.
        $second = $this->generate($token)->assertCreated();
        $this->assertSame('50.00', $second->json('data.totals.replacement_deductions'));
        $this->assertSame(1, DB::table('payroll_item_deductions')->where('kind', 'replacement')->count());
    }
}
