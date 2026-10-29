<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner-approved fixes of 2026-10-29 for Temporary replacements: a safe correction (Edit), an End Coverage that never lengthens a cover without
 * confirmation, "Expired - Needs Closing" with a close that keeps every date, protection of approved/finalized payroll, and a complete audit trail.
 * Synthetic data only; "today" is 20 December 2026.
 */
class TemporaryReplacementCorrectionTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private Admin $superAdmin;

    private string $super;

    private string $manager;

    private string $managerWithoutPayroll;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-12-20 10:00:00');
        [$this->superAdmin, $this->super] = $this->superAdminToken();
        $this->manager = $this->tokenWithOnly(['hr.view', 'hr.manage', 'hr.payroll.view', 'hr.payroll.approve', 'hr.payroll.manage']);
        $this->managerWithoutPayroll = $this->tokenWithOnly(['hr.view', 'hr.manage']);
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

    /** @return array{0: Employee, 1: EmployeeWorkAssignment} */
    private function position(int $dollars = 600): array
    {
        $permanent = $this->makeEmployee('active', 'permanent');

        return [$permanent, $this->makeAssignment($permanent, $this->makeCompany(fake()->unique()->company()), null, $dollars, '2026-01-01')];
    }

    private function cover(EmployeeWorkAssignment $a, string $start, ?string $end, bool $approved = false, string $status = 'active', ?Employee $temp = null): TemporaryReplacement
    {
        return TemporaryReplacement::query()->create([
            'work_assignment_id' => $a->id, 'replacement_employee_id' => ($temp ?? $this->makeEmployee('active', 'temporary'))->id,
            'start_date' => $start, 'end_date' => $end, 'currency' => 'USD', 'status' => $status, 'approved_at' => $approved ? now() : null,
            'reason' => 'Leave cover', 'created_by' => $this->superAdmin->id,
        ]);
    }

    private function version(TemporaryReplacement $cover): string
    {
        return $this->as($this->super)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/change-preview")->assertOk()->json('data.version');
    }

    private function edit(string $token, TemporaryReplacement $cover, array $payload, ?string $version = null)
    {
        $version ??= $this->version($cover->fresh()); // read first: reading the version signs in as the Super Admin

        return $this->as($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}", [...$payload, 'version' => $version]);
    }

    private function end(string $token, TemporaryReplacement $cover, array $payload)
    {
        return $this->as($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/end", $payload);
    }

    /** Generates, reviews, approves and finalizes a month through the real endpoints. */
    private function close(string $period): PayrollRun
    {
        $id = $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated()->json('data.id');
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/review")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/approve")->assertOk();
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$id}/finalize")->assertOk();

        return PayrollRun::query()->findOrFail($id);
    }

    private function replacementDeduction(string $period, Employee $permanent): ?string
    {
        $runId = $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated()->json('data.id');
        $line = collect($this->as($this->super)->getJson("/api/v1/admin/hr/payroll-runs/{$runId}")->json('data.items'))->firstWhere('employee_id', $permanent->id);
        $deduction = collect($line['deductions'])->firstWhere('kind', 'replacement');

        return $deduction === null ? null : (string) $deduction['amount'];
    }

    // ------------------------------------------------------------------------------------------ Edit: what can change

    public function test_a_correction_changes_only_the_permitted_fields_and_keeps_the_assignment_the_salary_and_the_payments(): void
    {
        [$permanent, $position] = $this->position();
        $cover = $this->cover($position, '2026-10-01', '2026-10-10');
        $cover->payments()->create(['payment_date' => '2026-10-05', 'amount' => 50, 'paid_by' => $this->superAdmin->id]);
        $salaryBefore = $position->fresh()->salary_amount;

        $this->edit($this->manager, $cover, ['start_date' => '2026-10-02', 'end_date' => '2026-10-12', 'reason' => 'Annual leave', 'notes' => 'Corrected by HR', 'change_reason' => 'Typed the wrong dates at registration'])
            ->assertOk()
            ->assertJsonPath('data.start_date', '2026-10-02')
            ->assertJsonPath('data.end_date', '2026-10-12')
            ->assertJsonPath('data.reason', 'Annual leave');

        $fresh = $cover->fresh();
        $this->assertSame('2026-10-02', $fresh->start_date->toDateString());
        $this->assertSame('Corrected by HR', $fresh->notes);
        $this->assertSame($position->id, $fresh->work_assignment_id, 'the covered assignment never changes');
        $this->assertSame($salaryBefore, $position->fresh()->salary_amount, 'the covered salary never changes');
        $this->assertSame('active', $position->fresh()->status->value);
        $this->assertSame(1, $cover->payments()->count(), 'the payment records are untouched');
        $this->assertSame('50.00', (string) $cover->payments()->first()->amount);
        $this->assertSame($permanent->id, $fresh->workAssignment->employee_id);
    }

    public function test_the_covered_assignment_the_status_and_the_approval_cannot_be_sent_at_all(): void
    {
        [, $position] = $this->position();
        [, $other] = $this->position();
        $cover = $this->cover($position, '2026-10-01', '2026-10-10');

        foreach ([['work_assignment_id' => $other->id], ['status' => 'ended'], ['approved_at' => '2026-10-01']] as $forbidden) {
            $this->edit($this->manager, $cover, ['reason' => 'x', 'change_reason' => 'Trying to change a forbidden field', ...$forbidden])->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');
        }

        $this->assertSame($position->id, $cover->fresh()->work_assignment_id);
        $this->assertSame('active', $cover->fresh()->status->value);
    }

    public function test_a_correction_needs_a_written_reason_and_the_version_the_screen_showed(): void
    {
        [, $position] = $this->position();
        $cover = $this->cover($position, '2026-10-01', '2026-10-10');

        $this->as($this->manager)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}", ['end_date' => '2026-10-11', 'version' => $this->version($cover)])->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');
        $this->as($this->manager)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}", ['end_date' => '2026-10-11', 'change_reason' => 'too short'])->assertStatus(422);
        $this->assertSame('2026-10-10', $cover->fresh()->end_date->toDateString());

        // Someone else changed it after the screen was loaded: the stale change is refused and nothing is overwritten.
        $stale = $this->version($cover);
        $this->edit($this->manager, $cover, ['reason' => 'First correction', 'change_reason' => 'First person corrected the reason'], $stale)->assertOk();
        $this->edit($this->manager, $cover, ['reason' => 'Second correction', 'change_reason' => 'Second person with an old screen'], $stale)->assertStatus(409)->assertJsonPath('error_code', 'TEMPORARY_REPLACEMENT_CHANGED');
        $this->assertSame('First correction', $cover->fresh()->reason);
    }

    public function test_invalid_dates_and_no_op_corrections_are_refused(): void
    {
        [, $position] = $this->position();
        $cover = $this->cover($position, '2026-10-01', '2026-10-10');

        $this->edit($this->manager, $cover, ['start_date' => '2026-10-20', 'change_reason' => 'Start after the end date'])->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_REPLACEMENT_END_BEFORE_START');
        $this->edit($this->manager, $cover, ['end_date' => '2026-09-30', 'change_reason' => 'End before the start date'])->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_REPLACEMENT_END_BEFORE_START');
        $this->edit($this->manager, $cover, ['start_date' => '2026-10-01', 'change_reason' => 'Nothing really changes here'])->assertStatus(422)->assertJsonPath('error_code', 'NO_CHANGES');

        $ended = $this->cover($position, '2026-11-01', '2026-11-05', false, 'ended');
        $this->edit($this->manager, $ended, ['end_date' => null, 'change_reason' => 'An ended cover needs a last day'])->assertStatus(422)->assertJsonPath('error_code', 'ENDED_COVER_NEEDS_END_DATE');
        $this->assertSame('2026-10-10', $cover->fresh()->end_date->toDateString());
    }

    public function test_the_temporary_employee_can_be_corrected_only_before_any_payment_or_workday_and_only_to_a_valid_temporary(): void
    {
        [, $position] = $this->position();
        $cover = $this->cover($position, '2026-10-01', '2026-10-10');
        $newTemp = $this->makeEmployee('active', 'temporary');
        $permanentPerson = $this->makeEmployee('active', 'permanent');

        $this->edit($this->manager, $cover, ['replacement_employee_id' => $permanentPerson->id, 'change_reason' => 'Chose the wrong kind of person'])->assertStatus(422)->assertJsonPath('error_code', 'REPLACEMENT_EMPLOYEE_NOT_TEMPORARY');

        $this->edit($this->manager, $cover, ['replacement_employee_id' => $newTemp->id, 'change_reason' => 'The wrong Temporary was selected'])->assertOk();
        $this->assertSame($newTemp->id, $cover->fresh()->replacement_employee_id);

        $cover->payments()->create(['payment_date' => '2026-10-05', 'amount' => 10, 'paid_by' => $this->superAdmin->id]);
        $another = $this->makeEmployee('active', 'temporary');
        $this->edit($this->manager, $cover, ['replacement_employee_id' => $another->id, 'change_reason' => 'Too late: a payment exists'])->assertStatus(422)->assertJsonPath('error_code', 'REPLACEMENT_EMPLOYEE_HAS_RECORDS');
        $this->assertSame($newTemp->id, $cover->fresh()->replacement_employee_id);
    }

    public function test_a_correction_cannot_create_an_overlap_with_another_cover_of_the_position(): void
    {
        [, $position] = $this->position();
        $first = $this->cover($position, '2026-10-01', '2026-10-10');
        $this->cover($position, '2026-10-20', '2026-10-30');

        $this->edit($this->manager, $first, ['end_date' => '2026-10-25', 'change_reason' => 'Would run into the next cover'])->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_POSITION');
        $this->assertSame('2026-10-10', $first->fresh()->end_date->toDateString());
        // It is never its own conflict: a change inside its own days is fine.
        $this->edit($this->manager, $first, ['end_date' => '2026-10-15', 'change_reason' => 'Extended into free days'])->assertOk();
    }

    // ------------------------------------------------------------------------------------------ approval and the cost

    public function test_adding_days_in_open_months_clears_the_approval_and_the_cost_is_deducted_again_only_after_it_is_approved_again(): void
    {
        [$permanent, $position] = $this->position(600);
        $cover = $this->cover($position, '2026-10-01', '2026-10-10', true);
        $this->assertSame('193.55', $this->replacementDeduction('2026-10', $permanent), '600 x 10 / 31');

        $response = $this->edit($this->manager, $cover, ['end_date' => '2026-10-12', 'change_reason' => 'The cover really lasted two more days'])->assertOk();
        $response->assertJsonPath('data.approved', false);
        $this->assertNull($cover->fresh()->approved_at);
        $this->assertNull($this->replacementDeduction('2026-10', $permanent), 'nothing is deducted until the new cost is approved');

        $this->as($this->manager)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/approve")->assertOk();
        $this->assertSame('232.26', $this->replacementDeduction('2026-10', $permanent), '600 x 12 / 31');

        $audit = AuditLog::query()->where('entity_type', TemporaryReplacement::class)->where('entity_id', $cover->id)->get()->firstWhere('metadata.event', 'temporary_replacement_corrected');
        $this->assertTrue($audit->metadata['approval_cleared']);
        $this->assertSame(2, $audit->metadata['days_added']);
    }

    public function test_removing_days_keeps_the_approval_and_the_smaller_cost_applies(): void
    {
        [$permanent, $position] = $this->position(600);
        $cover = $this->cover($position, '2026-10-01', '2026-10-10', true);

        $this->edit($this->manager, $cover, ['end_date' => '2026-10-05', 'change_reason' => 'Ended five days earlier than typed'])->assertOk()->assertJsonPath('data.approved', true);
        $this->assertSame('96.77', $this->replacementDeduction('2026-10', $permanent), '600 x 5 / 31');
    }

    // ------------------------------------------------------------------------------------------ closed payroll

    public function test_a_change_that_adds_or_removes_days_in_a_finalized_month_is_closed_payroll_correction_for_a_super_admin_only(): void
    {
        [$permanent, $position] = $this->position(600);
        $cover = $this->cover($position, '2026-09-21', '2026-09-30', true);
        $run = $this->close('2026-09');
        $netBefore = PayrollItem::query()->where('payroll_run_id', $run->id)->where('employee_id', $permanent->id)->sum('net_cents');
        $deductionBefore = $this->deductionOfRun($run, $permanent);
        $this->assertSame(20000, $deductionBefore, '600 x 10 / 30');

        // The HR manager is refused, whatever the dates, and nothing moves.
        $this->edit($this->manager, $cover, ['end_date' => '2026-09-25', 'change_reason' => 'Shorten a finalized month'])->assertStatus(403)->assertJsonPath('error_code', 'PAYROLL_CORRECTION_SUPER_ADMIN_ONLY');
        $this->end($this->manager, $cover, ['end_date' => '2026-09-25'])->assertStatus(403)->assertJsonPath('error_code', 'PAYROLL_CORRECTION_SUPER_ADMIN_ONLY');
        $this->assertSame('2026-09-30', $cover->fresh()->end_date->toDateString());
        $this->assertSame('active', $cover->fresh()->status->value);

        // The Super Admin needs a written reason for it (End Coverage carries it as `reason`; a correction always carries `change_reason`).
        $this->end($this->super, $cover, ['end_date' => '2026-09-25'])->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->assertSame('2026-09-30', $cover->fresh()->end_date->toDateString());
        $this->end($this->super, $cover, ['end_date' => '2026-09-25', 'reason' => 'The cover stopped on 25 September'])->assertOk();
        $this->assertSame(1, PayrollCorrection::query()->where('period', '2026-09')->count());
    }

    public function test_the_closed_run_is_never_rewritten_the_difference_is_recorded_as_a_correction_with_the_reason(): void
    {
        [$permanent, $position] = $this->position(600);
        $cover = $this->cover($position, '2026-09-21', '2026-09-30', true);
        $run = $this->close('2026-09');
        $itemsBefore = PayrollItem::query()->where('payroll_run_id', $run->id)->orderBy('id')->get(['id', 'gross_cents', 'net_cents', 'replacement_deduction_cents'])->toArray();

        $this->edit($this->super, $cover, ['end_date' => '2026-09-25', 'change_reason' => 'The cover stopped on 25 September'])->assertOk();

        $this->assertSame($itemsBefore, PayrollItem::query()->where('payroll_run_id', $run->id)->orderBy('id')->get(['id', 'gross_cents', 'net_cents', 'replacement_deduction_cents'])->toArray(), 'the finalized run is exactly as it was');
        $this->assertSame('finalized', $run->fresh()->status);
        $correction = PayrollCorrection::query()->where('employee_id', $permanent->id)->where('period', '2026-09')->firstOrFail();
        $this->assertSame('The cover stopped on 25 September', $correction->reason);
        $this->assertSame('cover_changed', $correction->action);
        $this->assertSame(10000, abs($correction->difference_cents), '5 days x $20 less deducted than recorded');

        $audit = AuditLog::query()->where('entity_type', TemporaryReplacement::class)->where('entity_id', $cover->id)->get()->firstWhere('metadata.event', 'temporary_replacement_corrected');
        $this->assertSame(['2026-09'], $audit->metadata['locked_periods_changed']);
        $this->assertFalse($audit->metadata['approval_cleared'], 'fewer days: the approval given still covers them');
    }

    public function test_a_cover_that_is_not_approved_is_protected_in_a_finalized_month_too(): void
    {
        [, $position] = $this->position(600);
        $cover = $this->cover($position, '2026-09-21', '2026-09-30', false);
        $this->close('2026-09');

        // No deduction exists, but the Temporary's amount for that month is worked out from the cover itself, so the days of a closed month do not move.
        $this->edit($this->manager, $cover, ['end_date' => '2026-09-28', 'change_reason' => 'Shorten an unapproved cover'])->assertStatus(403)->assertJsonPath('error_code', 'PAYROLL_CORRECTION_SUPER_ADMIN_ONLY');
        $this->assertSame('2026-09-30', $cover->fresh()->end_date->toDateString());

        $this->edit($this->super, $cover, ['end_date' => '2026-09-28', 'change_reason' => 'Short'])->assertStatus(422);
        $this->edit($this->super, $cover, ['end_date' => '2026-09-28', 'change_reason' => 'Corrected: the cover stopped on 28 September'])->assertOk();
        $this->assertSame('2026-09-28', $cover->fresh()->end_date->toDateString());
    }

    public function test_a_change_that_leaves_every_finalized_month_as_it_was_needs_no_correction(): void
    {
        [, $position] = $this->position(600);
        $cover = $this->cover($position, '2026-09-21', '2026-09-30', true);
        $this->close('2026-09');

        // The reason and the notes carry no day: allowed for the HR manager, and no correction is recorded.
        $this->edit($this->manager, $cover, ['reason' => 'Annual leave of the permanent employee', 'notes' => 'Added later', 'change_reason' => 'Wording of the reason was incomplete'])->assertOk();
        $this->assertSame(0, PayrollCorrection::query()->count());
        $this->assertNotNull($cover->fresh()->approved_at, 'the approval of a finalized month is not touched by a wording change');
    }

    private function deductionOfRun(PayrollRun $run, Employee $permanent): int
    {
        return (int) PayrollItem::query()->where('payroll_run_id', $run->id)->where('employee_id', $permanent->id)->sum('replacement_deduction_cents');
    }

    // ------------------------------------------------------------------------------------------ End Coverage

    public function test_end_coverage_refuses_a_last_day_before_the_start_and_does_not_lengthen_a_cover_without_confirmation(): void
    {
        [, $position] = $this->position();
        $cover = $this->cover($position, '2026-11-01', '2026-11-10');

        $this->end($this->manager, $cover, ['end_date' => '2026-10-31'])->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_REPLACEMENT_END_BEFORE_START');
        $this->end($this->manager, $cover, ['end_date' => '2026-11-15'])->assertStatus(422)->assertJsonPath('error_code', 'EXTENSION_CONFIRMATION_REQUIRED')
            ->assertJsonPath('errors.old_end_date', '2026-11-10')->assertJsonPath('errors.new_end_date', '2026-11-15')->assertJsonPath('errors.days_added', 5);
        $this->assertSame('active', $cover->fresh()->status->value);
        $this->assertSame('2026-11-10', $cover->fresh()->end_date->toDateString());

        $this->end($this->manager, $cover, ['end_date' => '2026-11-15', 'confirm_extension' => true])->assertOk()->assertJsonPath('data.status', 'ended')->assertJsonPath('data.end_date', '2026-11-15');
    }

    public function test_ending_on_the_planned_end_or_earlier_needs_no_confirmation_and_keeps_the_plan(): void
    {
        [, $position] = $this->position();
        $onPlan = $this->cover($position, '2026-11-01', '2026-11-10');
        $this->end($this->manager, $onPlan, ['end_date' => '2026-11-10'])->assertOk()->assertJsonPath('data.planned_end_date', null);

        [, $other] = $this->position();
        $early = $this->cover($other, '2026-11-01', '2026-11-10');
        $this->end($this->manager, $early, ['end_date' => '2026-11-07'])->assertOk()->assertJsonPath('data.end_date', '2026-11-07')->assertJsonPath('data.planned_end_date', '2026-11-10');
    }

    public function test_an_extension_in_open_months_clears_the_approval_and_is_recorded_with_before_and_after(): void
    {
        [$permanent, $position] = $this->position(600);
        $cover = $this->cover($position, '2026-10-01', '2026-10-10', true);

        $this->end($this->manager, $cover, ['end_date' => '2026-10-14', 'confirm_extension' => true, 'notes' => 'Stayed four more days'])->assertOk()->assertJsonPath('data.approved', false);
        $this->assertNull($this->replacementDeduction('2026-10', $permanent));

        $audit = AuditLog::query()->where('entity_type', TemporaryReplacement::class)->where('entity_id', $cover->id)->get()->firstWhere('metadata.event', 'temporary_replacement_ended');
        $this->assertSame('2026-10-10', $audit->metadata['before']['end_date']);
        $this->assertSame('2026-10-14', $audit->metadata['after']['end_date']);
        $this->assertTrue($audit->metadata['extension']);
        $this->assertSame(4, $audit->metadata['days_added']);
        $this->assertTrue($audit->metadata['approval_cleared']);
        $this->assertNotNull($audit->admin_id, 'who made the change is recorded');
    }

    public function test_a_cover_that_has_ended_cannot_be_ended_again(): void
    {
        [, $position] = $this->position();
        $cover = $this->cover($position, '2026-11-01', '2026-11-10', false, 'ended');

        $this->end($this->manager, $cover, ['end_date' => '2026-11-12', 'confirm_extension' => true])->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_REPLACEMENT_NOT_ACTIVE');
    }

    // ------------------------------------------------------------------------------------------ preview

    public function test_the_preview_shows_the_old_and_new_dates_the_added_days_and_the_cost_without_saving_anything(): void
    {
        [, $position] = $this->position(600);
        $cover = $this->cover($position, '2026-11-01', '2026-11-10', true);

        $data = $this->as($this->manager)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/change-preview?end_date=2026-11-15")->assertOk()->json('data');

        $this->assertSame(['start_date' => '2026-11-01', 'end_date' => '2026-11-10'], $data['old']);
        $this->assertSame(['start_date' => '2026-11-01', 'end_date' => '2026-11-15'], $data['new']);
        $this->assertSame(10, $data['days_before']);
        $this->assertSame(15, $data['days_after']);
        $this->assertSame(5, $data['days_added']);
        $this->assertTrue($data['extends_end']);
        $this->assertTrue($data['increases_cost']);
        $this->assertSame(20000, $data['cost_before_cents'], '600 x 10 / 30');
        $this->assertSame(30000, $data['cost_after_cents'], '600 x 15 / 30');
        $this->assertSame(10000, $data['cost_delta_cents']);
        $this->assertTrue($data['approval_will_be_cleared']);
        $this->assertSame('2026-11-10', $cover->fresh()->end_date->toDateString(), 'a preview saves nothing');
        $this->assertNotNull($cover->fresh()->approved_at);
    }

    public function test_the_preview_hides_money_from_people_who_may_not_see_payroll_and_reports_closed_months_and_conflicts(): void
    {
        [, $position] = $this->position(600);
        $cover = $this->cover($position, '2026-09-21', '2026-09-30', true);
        $this->cover($position, '2026-10-05', '2026-10-09');
        $this->close('2026-09');

        $plain = $this->as($this->managerWithoutPayroll)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/change-preview?end_date=2026-09-25")->assertOk()->json('data');
        $this->assertArrayNotHasKey('cost_before_cents', $plain);
        $this->assertArrayNotHasKey('cost_delta_cents', $plain);
        $this->assertFalse($plain['sees_money']);
        $this->assertTrue($plain['changes_locked_period']);
        $this->assertSame(['2026-09'], $plain['locked_periods_changed']);
        $this->assertTrue($plain['requires_super_admin']);

        $asSuper = $this->as($this->super)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/change-preview?end_date=2026-09-25")->assertOk()->json('data');
        $this->assertFalse($asSuper['requires_super_admin']);
        $this->assertTrue($asSuper['requires_reason_for_closed_payroll']);

        $conflict = $this->as($this->super)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/change-preview?end_date=2026-10-07")->assertOk()->json('data');
        $this->assertSame('TEMPORARY_COVER_OVERLAPS_POSITION', $conflict['conflict']['code']);

        $reversed = $this->as($this->super)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/change-preview?end_date=2026-09-01")->assertOk()->json('data');
        $this->assertSame('TEMPORARY_REPLACEMENT_END_BEFORE_START', $reversed['errors'][0]['code']);
    }

    // ------------------------------------------------------------------------------------------ expired and close

    public function test_a_running_cover_whose_end_date_has_passed_is_expired_and_counted_apart_on_the_dashboard(): void
    {
        [, $position] = $this->position();
        [, $second] = $this->position();
        [, $third] = $this->position();
        [, $fourth] = $this->position();
        $expired = $this->cover($position, '2026-12-01', '2026-12-10');
        $running = $this->cover($second, '2026-12-15', '2026-12-31');
        $open = $this->cover($third, '2026-12-01', null);
        $scheduled = $this->cover($fourth, '2027-01-05', '2027-01-10');
        $ended = $this->cover($position, '2026-11-01', '2026-11-05', false, 'ended');

        $list = collect($this->as($this->manager)->getJson('/api/v1/admin/hr/temporary-replacements')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('expired', $list[$expired->id]['lifecycle']);
        $this->assertTrue($list[$expired->id]['is_expired']);
        $this->assertSame(10, $list[$expired->id]['days_overdue']);
        $this->assertSame('running', $list[$running->id]['lifecycle']);
        $this->assertSame('running', $list[$open->id]['lifecycle']);
        $this->assertSame('scheduled', $list[$scheduled->id]['lifecycle']);
        $this->assertSame('ended', $list[$ended->id]['lifecycle']);
        $this->assertFalse($list[$ended->id]['is_expired']);

        $dashboard = $this->as($this->super)->getJson('/api/v1/admin/hr/dashboard')->assertOk();
        $dashboard->assertJsonPath('data.active_temporary_replacements', 3)->assertJsonPath('data.expired_temporary_replacements', 1);
    }

    public function test_closing_an_expired_cover_keeps_every_date_and_the_cost_exactly_as_they_were(): void
    {
        [$permanent, $position] = $this->position(600);
        $cover = $this->cover($position, '2026-11-01', '2026-11-10', true);
        $before = $this->as($this->super)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/expected-payment")->assertOk()->json('data');
        $deductionBefore = $this->replacementDeduction('2026-11', $permanent);

        $this->as($this->manager)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/close")->assertOk()
            ->assertJsonPath('data.status', 'ended')->assertJsonPath('data.end_date', '2026-11-10')->assertJsonPath('data.approved', true)->assertJsonPath('data.lifecycle', 'ended');

        $after = $this->as($this->super)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/expected-payment")->assertOk()->json('data');
        $this->assertSame($before['expected'], $after['expected']);
        $this->assertSame($before['counted_days'], $after['counted_days']);
        $this->assertSame($deductionBefore, $this->replacementDeduction('2026-11', $permanent));
        $this->assertSame('2026-11-10', $cover->fresh()->end_date->toDateString());
        $this->assertNull($cover->fresh()->planned_end_date);

        $audit = AuditLog::query()->where('entity_type', TemporaryReplacement::class)->where('entity_id', $cover->id)->get()->firstWhere('metadata.event', 'temporary_replacement_closed_expired');
        $this->assertSame('2026-11-10', $audit->metadata['after']['end_date']);
        $this->assertSame(40, $audit->metadata['days_overdue']);
    }

    public function test_only_an_expired_cover_with_an_end_date_can_be_closed_that_way(): void
    {
        [, $a] = $this->position();
        [, $b] = $this->position();
        $running = $this->cover($a, '2026-12-15', '2026-12-31');
        $open = $this->cover($b, '2026-12-01', null);
        $ended = $this->cover($a, '2026-11-01', '2026-11-05', false, 'ended');

        foreach ([$running, $open] as $cover) {
            $this->as($this->manager)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/close")->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_REPLACEMENT_NOT_EXPIRED');
            $this->assertSame('active', $cover->fresh()->status->value);
        }

        $this->as($this->manager)->patchJson("/api/v1/admin/hr/temporary-replacements/{$ended->id}/close")->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_REPLACEMENT_NOT_ACTIVE');
    }

    // ------------------------------------------------------------------------------------------ authorization and history

    public function test_only_people_who_may_manage_hr_can_correct_end_close_or_preview(): void
    {
        [, $position] = $this->position();
        $cover = $this->cover($position, '2026-11-01', '2026-11-10');
        $viewer = $this->tokenWithOnly(['hr.view']);
        $nobody = $this->noPermissionToken();
        $version = $this->version($cover);

        foreach ([$viewer, $nobody] as $token) {
            $this->as($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}", ['reason' => 'x', 'change_reason' => 'Not allowed to do this', 'version' => $version])->assertForbidden();
            $this->as($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/end", ['end_date' => '2026-11-05'])->assertForbidden();
            $this->as($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/close")->assertForbidden();
            $this->as($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/change-preview")->assertForbidden();
        }

        $this->as($viewer)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/history")->assertOk();
        $this->as($nobody)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/history")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/history")->assertUnauthorized();
        $this->assertSame('2026-11-10', $cover->fresh()->end_date->toDateString());
    }

    public function test_the_history_says_who_changed_what_when_and_why_newest_first(): void
    {
        [, $position] = $this->position();
        $cover = $this->cover($position, '2026-10-01', '2026-10-10');
        $this->edit($this->manager, $cover, ['reason' => 'Annual leave', 'change_reason' => 'The reason was written wrongly'])->assertOk();
        CarbonImmutable::setTestNow('2026-12-20 11:00:00');
        $this->end($this->manager, $cover->fresh(), ['end_date' => '2026-10-08'])->assertOk();

        $rows = $this->as($this->managerWithoutPayroll)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/history")->assertOk()->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame('temporary_replacement_ended', $rows[0]['details']['event']);
        $this->assertSame('temporary_replacement_corrected', $rows[1]['details']['event']);
        $this->assertSame('The reason was written wrongly', $rows[1]['details']['reason']);
        $this->assertSame(['reason' => 'Leave cover'], array_intersect_key($rows[1]['details']['before'], ['reason' => 1]));
        $this->assertSame('Annual leave', $rows[1]['details']['after']['reason']);
        $this->assertNotNull($rows[0]['by']);
        $this->assertNotNull($rows[0]['at']);
    }
}
