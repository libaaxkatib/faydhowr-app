<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\AssignmentAbsence;
use App\Models\AssignmentCycleEvent;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use App\Models\EmployeeSeparation;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Replacement cycles: the ORIGINAL Permanent assignment is the one canonical record of a position. While its holder is away it stays
 * the same row (same id, still open); the absence, each Replacement and the return are events linked to it, so any number of cycles
 * can follow one another without a new Permanent row each time the person comes back.
 *
 * Payroll rule under test: every calendar day of the position is paid exactly once - to the original on the days they hold it, to the
 * Replacement on the days the Replacement holds it, and to nobody on a gap day (reported). The October 2026 scenario of the owner:
 * last working day Oct 11, Replacement starts Oct 12, original returns Oct 13.
 */
class ReplacementCycleTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $company;

    private Position $cleaner;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
        $this->company = $this->makeCompany('4Season Res');
        $this->cleaner = Position::query()->create(['name' => 'Cleaner T']);
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

    private function url(string $tail = 'employees'): string
    {
        return "/api/v1/admin/hr/client-companies/{$this->company->id}/{$tail}";
    }

    /** The original Permanent assignment: Oct 1 until further notice, $310 a month so one calendar day of October is exactly $10. */
    private function original(string $start = '2026-10-01', int $salary = 310): EmployeeWorkAssignment
    {
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->company, null, $salary, $start);
        $assignment->update(['position_id' => $this->cleaner->id]);

        return $assignment;
    }

    private function replace(EmployeeWorkAssignment $original, ?Employee $replacement = null, string $lastDay = '2026-10-11', string $from = '2026-10-12')
    {
        return $this->as($this->admin)->postJson($this->url()."/{$original->id}/replace", [
            'replacement_employee_id' => ($replacement ?? $this->makeEmployee())->id, 'replacement_date' => $from, 'original_last_day' => $lastDay,
            'assignment_type' => 'replacement', 'position_id' => $this->cleaner->id,
        ]);
    }

    /** The salary is always confirmed by HR; unless a test says otherwise it is the assignment's own ($310). */
    private function giveBack(int $replacementAssignmentId, string $returnDate, int $salary = 310)
    {
        return $this->as($this->admin)->postJson($this->url()."/{$replacementAssignmentId}/return", ['return_date' => $returnDate, 'salary_amount' => $salary, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly']);
    }

    /** The absences of an assignment as plain dates (SQLite stores dates as datetimes, so the raw column is not compared). */
    private function absences(EmployeeWorkAssignment $original): array
    {
        return AssignmentAbsence::query()->where('work_assignment_id', $original->id)->orderBy('absent_from')->get()->map(fn (AssignmentAbsence $a): array => [
            'last_working_day' => $a->last_working_day->toDateString(),
            'absent_from' => $a->absent_from->toDateString(),
            'returned_on' => $a->returned_on?->toDateString(),
            'terminated_on' => $a->terminated_on?->toDateString(),
        ])->all();
    }

    private function stop(EmployeeWorkAssignment $original, string $lastDay, array $over = [])
    {
        return $this->as($this->admin)->postJson($this->url()."/{$original->id}/stop", array_replace(['end_date' => $lastDay, 'reason' => 'left the company'], $over));
    }

    /** @return array<int, PayrollItem> work_assignment_id => item */
    private function october(): array
    {
        $this->as($this->admin)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertSuccessful();

        return PayrollItem::query()->where('period', '2026-10')->get()->keyBy('work_assignment_id')->all();
    }

    // ------------------------------------------------------------------ the original assignment stays the canonical record

    public function test_the_october_11_12_13_sequence_is_accepted_and_the_original_row_stays_open(): void
    {
        $original = $this->original();
        $stand = $this->makeEmployee();

        $response = $this->replace($original, $stand)->assertOk();

        $original->refresh();
        $this->assertSame('active', $original->status->value, 'the original is away, not ended: it is the same open row');
        $this->assertNull($original->end_date);

        $replacementId = $response->json('data.replacement.id');
        $replacement = EmployeeWorkAssignment::query()->findOrFail($replacementId);
        $this->assertSame($original->id, $replacement->replaces_assignment_id);
        $this->assertSame('replacement', $replacement->assignment_type->value);
        $this->assertSame('2026-10-12', $replacement->start_date->toDateString());
        $this->assertNull($replacement->end_date, 'open until the original returns');
        $this->assertSame('310.00', (string) $replacement->salary_amount, "the Replacement's basis is the original's salary");

        $this->assertSame([['last_working_day' => '2026-10-11', 'absent_from' => '2026-10-12', 'returned_on' => null, 'terminated_on' => null]], $this->absences($original));

        $return = $this->giveBack($replacementId, '2026-10-13')->assertOk();

        $this->assertSame($original->id, $return->json('data.returned.id'), 'the person returns to the SAME assignment id');
        $replacement->refresh();
        $this->assertSame('ended', $replacement->status->value);
        $this->assertSame('2026-10-12', $replacement->end_date->toDateString());
        $this->assertSame(2, EmployeeWorkAssignment::query()->where('client_company_id', $this->company->id)->count(), 'no new Permanent row is created');
        $this->assertSame('active', $original->fresh()->status->value);
        $this->assertNull($original->fresh()->end_date);
        $this->assertSame('2026-10-13', $this->absences($original)[0]['returned_on']);
    }

    public function test_the_salary_is_confirmed_on_return_and_an_edit_is_a_salary_change_of_the_same_assignment(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');

        $this->as($this->admin)->postJson($this->url()."/{$replacementId}/return", ['return_date' => '2026-10-13'])
            ->assertUnprocessable()->assertJsonValidationErrors(['salary_amount', 'salary_currency', 'salary_frequency'], 'errors');
        $this->assertNull(EmployeeWorkAssignment::query()->findOrFail($replacementId)->end_date, 'nothing is saved without the confirmed salary');

        $this->giveBack($replacementId, '2026-10-13', 330)->assertOk()->assertJsonPath('data.returned.id', $original->id);

        $this->assertSame(2, $original->salaryHistories()->count(), 'the old salary row is kept, a new one starts on the return date');
        $this->assertSame('2026-10-13', $original->salaryHistories()->get()->last()->effective_from->toDateString());
        $this->assertSame('330.00', (string) $original->fresh()->salary_amount);
        $this->assertSame(2, EmployeeWorkAssignment::query()->where('client_company_id', $this->company->id)->count(), 'still no second Permanent row');
    }

    public function test_the_validation_message_names_the_last_working_day_when_that_is_what_is_wrong(): void
    {
        $original = $this->original('2026-10-05');

        $this->replace($original, null, '2026-10-02', '2026-10-03')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'REPLACEMENT_BEFORE_ASSIGNMENT_START')
            ->assertJsonValidationErrors(['original_last_day'], 'errors');

        $this->assertNull($original->fresh()->end_date);
        $this->assertDatabaseCount('assignment_absences', 0);
    }

    public function test_every_event_of_the_cycle_is_linked_to_the_original_assignment(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $this->giveBack($replacementId, '2026-10-13')->assertOk();

        $history = $this->as($this->admin)->getJson($this->url('employee-history'))->assertOk()->json('data');

        $this->assertCount(1, $history['cycles']);
        $cycle = $history['cycles'][0];
        $this->assertSame($original->id, $cycle['assignment_id']);
        $this->assertSame('2026-10-11', $cycle['last_working_day']);
        $this->assertSame('2026-10-12', $cycle['absent_from']);
        $this->assertSame('2026-10-13', $cycle['returned_on']);

        $events = collect($cycle['events'])->mapWithKeys(fn ($e) => [$e['event_type'] => $e]);
        $this->assertSame(['absence_started', 'replacement_started', 'replacement_ended', 'returned'], $events->keys()->all());
        $this->assertSame('2026-10-11', $events['absence_started']['event_date'], 'the last working day');
        $this->assertSame('2026-10-12', $events['replacement_started']['event_date']);
        $this->assertSame($replacementId, $events['replacement_started']['replacement_assignment_id']);
        $this->assertSame('2026-10-12', $events['replacement_ended']['event_date'], "the Replacement's last day");
        $this->assertSame('2026-10-13', $events['returned']['event_date']);
    }

    public function test_several_cycles_follow_one_another_on_the_same_assignment_without_overwriting_history(): void
    {
        $original = $this->original();
        $first = $this->replace($original)->assertOk()->json('data.replacement.id');
        $this->giveBack($first, '2026-10-13')->assertOk();

        $second = $this->replace($original, null, '2026-10-19', '2026-10-20')->assertOk()->json('data.replacement.id');
        $this->giveBack($second, '2026-10-23')->assertOk();

        $this->assertSame(3, EmployeeWorkAssignment::query()->where('client_company_id', $this->company->id)->count(), 'the original and one row per Replacement - never a second Permanent row');
        $this->assertDatabaseCount('assignment_absences', 2);
        $this->assertSame([['2026-10-12', '2026-10-13'], ['2026-10-20', '2026-10-23']], array_map(fn (array $a): array => [$a['absent_from'], $a['returned_on']], $this->absences($original)));

        $history = $this->as($this->admin)->getJson($this->url('employee-history'))->json('data');
        $this->assertCount(2, $history['cycles']);
    }

    // ------------------------------------------------------------------ guards

    public function test_a_second_absence_cannot_start_while_one_is_still_open(): void
    {
        $original = $this->original();
        $this->replace($original)->assertOk();

        $this->replace($original, null, '2026-10-20', '2026-10-21')->assertUnprocessable()->assertJsonPath('error_code', 'ABSENCE_ALREADY_OPEN');
        $this->assertDatabaseCount('assignment_absences', 1);
    }

    public function test_a_new_cycle_cannot_reach_back_into_an_earlier_one(): void
    {
        $original = $this->original();
        $first = $this->replace($original)->assertOk()->json('data.replacement.id');
        $this->giveBack($first, '2026-10-15')->assertOk();

        $this->replace($original, null, '2026-10-13', '2026-10-14')->assertUnprocessable()->assertJsonPath('error_code', 'ABSENCE_OVERLAPS_EARLIER_CYCLE');
        $this->assertDatabaseCount('assignment_absences', 1);
    }

    public function test_the_return_date_must_be_after_the_replacement_started_and_a_refused_return_changes_nothing(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');

        $this->giveBack($replacementId, '2026-10-12')->assertUnprocessable()->assertJsonPath('error_code', 'RETURN_BEFORE_REPLACEMENT_STARTED');

        $this->assertNull(EmployeeWorkAssignment::query()->findOrFail($replacementId)->end_date);
        $this->assertNull($this->absences($original)[0]['returned_on']);
    }

    // ------------------------------------------------------------------ Option B: the holder may be terminated while away

    public function test_terminating_an_absent_holder_asks_for_confirmation_and_changes_nothing_until_it_is_given(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');

        $response = $this->stop($original, '2026-10-20')->assertUnprocessable()->assertJsonPath('error_code', 'REPLACEMENT_CONTINUES_CONFIRMATION_REQUIRED');

        $this->assertStringContainsString('does NOT end the Replacement', $response->json('message'));
        $this->assertSame([$replacementId], $response->json('errors.replacement_assignment_ids'));
        $this->assertSame('active', $original->fresh()->status->value);
        $this->assertNull($original->fresh()->end_date);
        $this->assertSame('active', EmployeeWorkAssignment::query()->findOrFail($replacementId)->status->value);
        $this->assertNull($this->absences($original)[0]['terminated_on']);
    }

    public function test_a_confirmed_termination_ends_only_the_holder_and_keeps_the_replacement_the_link_and_the_history(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');

        $this->stop($original, '2026-10-20', ['confirm_replacement_continues' => true])->assertOk();

        $original->refresh();
        $this->assertSame('ended', $original->status->value);
        $this->assertSame('2026-10-20', $original->end_date->toDateString());

        $replacement = EmployeeWorkAssignment::query()->findOrFail($replacementId);
        $this->assertSame('active', $replacement->status->value, 'the Replacement is not ended by the termination');
        $this->assertNull($replacement->end_date);
        $this->assertSame($original->id, $replacement->replaces_assignment_id, 'the link to the original assignment is kept');
        $this->assertSame('replacement', $replacement->assignment_type->value);
        $this->assertSame(2, EmployeeWorkAssignment::query()->where('client_company_id', $this->company->id)->count());

        $this->assertSame('2026-10-20', $this->absences($original)[0]['terminated_on']);
        $this->assertNull($this->absences($original)[0]['returned_on'], 'the absence is never closed by a return that cannot happen');

        $history = $this->as($this->admin)->getJson($this->url('employee-history'))->assertOk()->json('data');
        $types = array_column($history['cycles'][0]['events'], 'event_type');
        $this->assertSame(['absence_started', 'replacement_started', 'original_terminated'], $types);
        $this->assertSame([$replacementId], $history['cycles'][0]['events'][2]['details']['replacement_assignment_ids']);
        $this->assertTrue($history['cycles'][0]['events'][2]['details']['replacements_left_working']);
        $audit = array_column($history['events'], 'event');
        $this->assertContains('replaced', $audit, 'the audit trail of the replacement is still there');
        $this->assertContains('stopped', $audit);
    }

    public function test_the_last_day_of_a_terminated_absent_holder_cannot_precede_the_last_working_day(): void
    {
        $original = $this->original();
        $this->replace($original)->assertOk();

        $this->stop($original, '2026-10-05', ['confirm_replacement_continues' => true])
            ->assertUnprocessable()->assertJsonPath('error_code', 'TERMINATION_BEFORE_LAST_WORKING_DAY');
        $this->assertNull($original->fresh()->end_date);
    }

    public function test_nobody_can_be_returned_to_an_assignment_that_was_terminated_while_away(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $this->stop($original, '2026-10-20', ['confirm_replacement_continues' => true])->assertOk();

        $this->giveBack($replacementId, '2026-10-25')->assertUnprocessable()->assertJsonPath('error_code', 'RETURN_ORIGINAL_TERMINATED');

        $replacement = EmployeeWorkAssignment::query()->findOrFail($replacementId);
        $this->assertSame('active', $replacement->status->value);
        $this->assertNull($replacement->end_date);
    }

    public function test_separating_the_employee_while_away_follows_the_same_confirmation_and_leaves_the_replacement_working(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $body = ['reason' => 'resigned', 'separation_date' => '2026-10-20', 'rehire_eligible' => true, 'notes' => 'HR confirmed'];

        $this->as($this->admin)->patchJson("/api/v1/admin/hr/employees/{$original->employee_id}/separate", $body)
            ->assertUnprocessable()->assertJsonPath('error_code', 'REPLACEMENT_CONTINUES_CONFIRMATION_REQUIRED');
        $this->assertSame('active', $original->fresh()->status->value);

        $this->as($this->admin)->patchJson("/api/v1/admin/hr/employees/{$original->employee_id}/separate", $body + ['confirm_replacement_continues' => true])->assertOk();

        $this->assertSame('ended', $original->fresh()->status->value);
        $this->assertSame('2026-10-20', $original->fresh()->end_date->toDateString());
        $this->assertSame('active', EmployeeWorkAssignment::query()->findOrFail($replacementId)->status->value, 'separation never ends the Replacement');
        $this->assertSame('2026-10-20', $this->absences($original)[0]['terminated_on']);
    }

    public function test_editing_the_end_date_cannot_skip_the_confirmation(): void
    {
        $original = $this->original();
        $this->replace($original)->assertOk();

        $this->as($this->admin)->putJson("/api/v1/admin/hr/work-assignments/{$original->id}", ['end_date' => '2026-10-20'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'USE_STOP_FOR_ABSENT_ASSIGNMENT');
        $this->assertNull($original->fresh()->end_date);
    }

    public function test_a_full_location_still_accepts_the_replacement_because_the_absent_original_no_longer_holds_the_seat(): void
    {
        $location = $this->makeClientLocation($this->company, 'Main Site', 1);
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, $location, 310, '2026-10-01');
        $original->update(['position_id' => $this->cleaner->id]);

        $this->replace($original)->assertOk();

        $this->assertSame(1, EmployeeWorkAssignment::query()->where('replaces_assignment_id', $original->id)->count());
    }

    // ------------------------------------------------------------------ payroll: each calendar day is paid once

    public function test_payroll_pays_every_day_once_across_absence_replacement_and_return(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $this->giveBack($replacementId, '2026-10-13')->assertOk();

        $items = $this->october();

        $this->assertSame(30, (int) $items[$original->id]->eligible_days, 'Oct 1-11 and Oct 13-31: Oct 12 belongs to the Replacement');
        $this->assertSame(30000, (int) $items[$original->id]->gross_cents, '30 days x $10');
        $this->assertSame(1, (int) $items[$replacementId]->eligible_days);
        $this->assertSame(1000, (int) $items[$replacementId]->gross_cents, '1 day x $10');
        $this->assertSame(31000, (int) $items[$original->id]->gross_cents + (int) $items[$replacementId]->gross_cents, 'the position costs exactly one month: no day twice, none lost');
    }

    public function test_payroll_before_the_return_stops_paying_the_original_from_the_first_day_of_absence(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');

        $items = $this->october();

        $this->assertSame(11, (int) $items[$original->id]->eligible_days, 'Oct 1-11 only: the person has not returned');
        $this->assertSame(20, (int) $items[$replacementId]->eligible_days, 'Oct 12-31');
        $this->assertSame(31000, (int) $items[$original->id]->gross_cents + (int) $items[$replacementId]->gross_cents);
    }

    public function test_payroll_with_two_cycles_pays_each_day_once(): void
    {
        $original = $this->original();
        $first = $this->replace($original)->assertOk()->json('data.replacement.id');
        $this->giveBack($first, '2026-10-13')->assertOk();
        $second = $this->replace($original, null, '2026-10-19', '2026-10-20')->assertOk()->json('data.replacement.id');
        $this->giveBack($second, '2026-10-23')->assertOk();

        $items = $this->october();

        $this->assertSame(27, (int) $items[$original->id]->eligible_days, '31 - Oct 12 - Oct 20, 21, 22');
        $this->assertSame(1, (int) $items[$first]->eligible_days);
        $this->assertSame(3, (int) $items[$second]->eligible_days);
        $this->assertSame(31000, (int) $items[$original->id]->gross_cents + (int) $items[$first]->gross_cents + (int) $items[$second]->gross_cents);
    }

    public function test_payroll_after_the_holder_is_terminated_while_away_pays_each_day_once_and_the_replacement_keeps_being_paid(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $this->stop($original, '2026-10-20', ['confirm_replacement_continues' => true])->assertOk();

        $items = $this->october();

        $this->assertSame(11, (int) $items[$original->id]->eligible_days, 'paid to the last working day only: the termination date inside the absence adds no day');
        $this->assertSame(20, (int) $items[$replacementId]->eligible_days, 'Oct 12-31 and still going');
        $this->assertSame(31000, (int) $items[$original->id]->gross_cents + (int) $items[$replacementId]->gross_cents, 'no day twice, none lost');

        $warnings = implode(' ', (array) $this->as($this->admin)->getJson('/api/v1/admin/hr/payroll-runs/'.PayrollItem::query()->first()->payroll_run_id)->json('data.warnings'));
        $this->assertStringNotContainsString('DOUBLE PAY', $warnings);
    }

    public function test_payroll_when_the_replacement_is_later_replaced_by_a_permanent_successor_still_counts_every_day_once(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $this->stop($original, '2026-10-20', ['confirm_replacement_continues' => true])->assertOk();

        // HR then makes an explicit decision about the position: a Permanent successor takes over the Replacement's seat from Oct 25.
        $successorId = $this->as($this->admin)->postJson($this->url()."/{$replacementId}/replace", [
            'replacement_employee_id' => $this->makeEmployee()->id, 'replacement_date' => '2026-10-25', 'assignment_type' => 'permanent',
            'position_id' => $this->cleaner->id, 'salary_amount' => 310, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertOk()->json('data.replacement.id');

        $items = $this->october();

        $this->assertSame(11, (int) $items[$original->id]->eligible_days);
        $this->assertSame(13, (int) $items[$replacementId]->eligible_days, 'Oct 12-24');
        $this->assertSame(7, (int) $items[$successorId]->eligible_days, 'Oct 25-31');
        $this->assertSame(31000, (int) $items[$original->id]->gross_cents + (int) $items[$replacementId]->gross_cents + (int) $items[$successorId]->gross_cents);
    }

    public function test_a_gap_day_between_the_last_working_day_and_the_replacement_is_paid_to_nobody(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original, null, '2026-10-10', '2026-10-12')->assertOk()->json('data.replacement.id');
        $this->giveBack($replacementId, '2026-10-13')->assertOk();

        $items = $this->october();

        $this->assertSame(29, (int) $items[$original->id]->eligible_days, 'Oct 11 (gap) and Oct 12 are not paid to the original');
        $this->assertSame(1, (int) $items[$replacementId]->eligible_days);
        $this->assertSame(30000, (int) $items[$original->id]->gross_cents + (int) $items[$replacementId]->gross_cents, 'the gap day costs nothing');
    }

    public function test_cent_remainders_still_reconcile_to_the_whole_positions_amount(): void
    {
        $original = $this->original('2026-10-01', 300);
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $this->giveBack($replacementId, '2026-10-13', 300)->assertOk();

        $items = $this->october();

        $this->assertSame(30000, (int) $items[$original->id]->gross_cents + (int) $items[$replacementId]->gross_cents, '$300 for the 31-day month, cents included');
    }

    // ------------------------------------------------------------------ the company screen and the picker

    public function test_the_company_list_counts_the_position_once_while_the_holder_is_away(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');

        $data = $this->as($this->admin)->getJson($this->url().'?period=2026-10')->assertOk()->json('data');
        $rows = collect($data['employees'])->keyBy('assignment_id');

        $this->assertSame(11, $rows[$original->id]['payable_days'], 'Oct 1-11: the days away are not payable to the holder');
        $this->assertSame(20, $rows[$original->id]['away_days_in_period']);
        $this->assertSame('2026-10-12', $rows[$original->id]['away']['absent_from']);
        $this->assertFalse($rows[$original->id]['holds_position_at_month_end']);
        $this->assertTrue($rows[$replacementId]['holds_position_at_month_end']);
        $this->assertNull($rows[$replacementId]['away']);
        $this->assertSame(20, $rows[$replacementId]['payable_days']);
        $this->assertSame(['USD' => 31000], $data['summary']['contracted_monthly_salary_cents_by_currency'], 'one position, counted once, for the person who holds it at month end');

        $this->giveBack($replacementId, '2026-10-13')->assertOk();
        $rows = collect($this->as($this->admin)->getJson($this->url().'?period=2026-10')->json('data.employees'))->keyBy('assignment_id');
        $this->assertSame(30, $rows[$original->id]['payable_days']);
        $this->assertNull($rows[$original->id]['away']);
        $this->assertTrue($rows[$original->id]['holds_position_at_month_end']);
        $this->assertFalse($rows[$replacementId]['holds_position_at_month_end']);
    }

    public function test_the_picker_offers_activate_only_where_the_hr_step_allows_it_and_the_actor_may_do_it(): void
    {
        $waiting = $this->makeEmployee('waiting');
        $inactive = $this->makeEmployee('inactive');
        $applicant = $this->makeEmployee('applicant');
        $active = $this->makeEmployee();

        $find = fn (string $token, Employee $e): array => collect($this->as($token)->getJson($this->url('employee-candidates').'?q='.$e->employee_number)->assertOk()->json('data'))->firstWhere('id', $e->id);

        $w = $find($this->admin, $waiting);
        $this->assertFalse($w['eligible']);
        $this->assertSame('status', $w['activation']);
        $this->assertTrue($w['can_activate']);

        $this->assertSame('rehire', $find($this->admin, $inactive)['activation']);
        $this->assertTrue($find($this->admin, $inactive)['can_activate']);

        $this->assertNull($find($this->admin, $applicant)['activation'], 'an early pipeline stage is not a shortcut to Active');
        $this->assertFalse($find($this->admin, $applicant)['can_activate']);

        $this->assertNull($find($this->admin, $active)['activation']);
        $this->assertTrue($find($this->admin, $active)['eligible']);

        $viewer = $this->tokenWithOnly(['hr.view']);
        $this->assertFalse($find($viewer, $waiting)['can_activate'], 'someone who may only view HR is never offered Activate');
    }

    public function test_after_activation_through_the_hr_step_the_same_person_can_be_chosen_as_the_replacement(): void
    {
        $original = $this->original();
        $waiting = $this->makeEmployee('waiting');

        $this->replace($original, $waiting)->assertUnprocessable()->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');
        $this->assertDatabaseCount('assignment_absences', 0);

        $this->as($this->admin)->patchJson("/api/v1/admin/hr/employees/{$waiting->id}/status", ['status' => 'active'])->assertOk();

        $this->replace($original, $waiting)->assertOk();
        $this->assertSame($waiting->id, EmployeeWorkAssignment::query()->where('replaces_assignment_id', $original->id)->value('employee_id'));
    }

    // ------------------------------------------------------------------ data shaped before this workflow keeps working

    public function test_a_replacement_whose_original_was_already_ended_still_returns_the_old_way(): void
    {
        $original = $this->original('2026-10-01');
        $original->update(['end_date' => '2026-10-11', 'status' => 'ended']);
        $stand = $this->makeEmployee();
        $replacement = EmployeeWorkAssignment::query()->create([
            'employee_id' => $stand->id, 'client_company_id' => $this->company->id, 'position_id' => $this->cleaner->id, 'replaces_assignment_id' => $original->id,
            'assignment_type' => 'replacement', 'start_date' => '2026-10-12', 'salary_amount' => 310, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly', 'status' => 'active',
        ]);
        $replacement->salaryHistories()->create(['effective_from' => '2026-10-12', 'amount' => 310, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'fixture']);

        $result = $this->as($this->admin)->postJson($this->url()."/{$replacement->id}/return", ['return_date' => '2026-10-13', 'salary_amount' => 310, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'])->assertOk();

        $this->assertNotSame($original->id, $result->json('data.returned.id'), 'the old shape cannot be reopened without a reconciliation: it keeps creating the follow-up row');
        $this->assertSame(3, EmployeeWorkAssignment::query()->where('client_company_id', $this->company->id)->count());
    }

    public function test_a_permanent_successor_still_ends_the_original_as_before(): void
    {
        $original = $this->original();

        $this->as($this->admin)->postJson($this->url()."/{$original->id}/replace", [
            'replacement_employee_id' => $this->makeEmployee()->id, 'replacement_date' => '2026-10-12', 'original_last_day' => '2026-10-11',
            'assignment_type' => 'permanent', 'position_id' => $this->cleaner->id, 'salary_amount' => 280, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertOk();

        $this->assertSame('ended', $original->fresh()->status->value);
        $this->assertSame('2026-10-11', $original->fresh()->end_date->toDateString());
        $this->assertDatabaseCount('assignment_absences', 0);
    }

    // ------------------------------------------------------------------ review findings: guard rails, swap, deductions, gap days

    public function test_a_position_that_has_had_a_finished_cycle_still_needs_its_successor_when_it_is_ended(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $this->giveBack($replacementId, '2026-10-13')->assertOk();

        // The finished Replacement is history; it must not count as "the next holder" and waive the replacement choice (N1).
        $this->stop($original, '2026-10-31')->assertUnprocessable()->assertJsonPath('error_code', 'SUCCESSOR_REQUIRED');
        $this->assertSame('active', $original->fresh()->status->value);
    }

    public function test_cancelling_a_cover_entered_by_mistake_closes_its_absence_with_zero_days_and_the_same_cover_can_be_entered_again(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');

        $this->as($this->admin)->patchJson("/api/v1/admin/hr/work-assignments/{$replacementId}/cancel", ['reason' => 'entered by mistake'])->assertOk();

        $this->assertSame('cancelled', EmployeeWorkAssignment::query()->findOrFail($replacementId)->status->value);
        $this->assertSame([['last_working_day' => '2026-10-11', 'absent_from' => '2026-10-12', 'returned_on' => '2026-10-12', 'terminated_on' => null]], $this->absences($original), 'a zero-day absence: the holder was never away for payroll');
        $this->assertSame(
            ['absence_started', 'replacement_started', 'cover_cancelled'],
            AssignmentCycleEvent::query()->where('work_assignment_id', $original->id)->orderBy('id')->pluck('event_type')->all(),
        );

        // All 31 days are the holder's again; nothing is lost, and nothing is paid to the cancelled cover.
        $items = $this->october();
        $this->assertSame([31000], collect($items)->pluck('gross_cents')->all());

        // The correct cover can then be recorded with the very same dates.
        $this->replace($original)->assertOk();
        $this->assertCount(2, $this->absences($original));
    }

    public function test_a_replacement_covering_an_away_holder_cannot_be_ended_edited_or_separated(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $replacementRow = EmployeeWorkAssignment::query()->findOrFail($replacementId);

        $this->as($this->admin)->postJson($this->url()."/{$replacementId}/stop", ['end_date' => '2026-10-20', 'reason' => 'left'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'REPLACEMENT_COVERS_AN_ABSENCE');
        $this->as($this->admin)->patchJson("/api/v1/admin/hr/work-assignments/{$replacementId}/end", ['end_date' => '2026-10-20'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'REPLACEMENT_COVERS_AN_ABSENCE');
        $this->as($this->admin)->putJson("/api/v1/admin/hr/work-assignments/{$replacementId}", ['end_date' => '2026-10-20'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'REPLACEMENT_COVERS_AN_ABSENCE');
        $this->as($this->admin)->patchJson("/api/v1/admin/hr/employees/{$replacementRow->employee_id}/separate", ['reason' => 'resigned', 'separation_date' => '2026-10-20', 'rehire_eligible' => true, 'notes' => 'x'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'REPLACEMENT_COVERS_AN_ABSENCE');

        $this->assertSame('active', $replacementRow->fresh()->status->value, 'nothing was ended');
        $this->assertSame([['last_working_day' => '2026-10-11', 'absent_from' => '2026-10-12', 'returned_on' => null, 'terminated_on' => null]], $this->absences($original));
        $this->giveBack($replacementId, '2026-10-20')->assertOk();
    }

    public function test_a_bare_assignment_cannot_be_used_to_add_a_second_cover_to_an_away_holder(): void
    {
        $original = $this->original();
        $this->replace($original)->assertOk();
        $other = $this->makeEmployee();

        $this->as($this->admin)->postJson("/api/v1/admin/hr/employees/{$other->id}/work-assignments", [
            'client_company_id' => $this->company->id, 'replaces_assignment_id' => $original->id, 'position_id' => $this->cleaner->id,
            'assignment_type' => 'replacement', 'start_date' => '2026-10-20',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'SUCCESSION_ALREADY_REPLACED'); // refused by the existing rule before the cycle guard is even reached
        $this->assertSame(2, EmployeeWorkAssignment::query()->where('client_company_id', $this->company->id)->count(), 'nothing was created');
    }

    public function test_replacing_the_replacement_while_the_holder_is_away_is_a_swap_inside_the_same_absence_and_the_holder_can_still_return(): void
    {
        $original = $this->original();
        $firstId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $first = EmployeeWorkAssignment::query()->findOrFail($firstId);

        $secondId = $this->replace($first, null, '2026-10-19', '2026-10-20')->assertOk()->json('data.replacement.id');
        $second = EmployeeWorkAssignment::query()->findOrFail($secondId);

        $this->assertSame('ended', $first->fresh()->status->value);
        $this->assertSame('2026-10-19', $first->fresh()->end_date->toDateString());
        $this->assertSame($original->id, $second->replaces_assignment_id, 'the new cover points at the holder who is away, so Return finds them');
        $this->assertSame(1, AssignmentAbsence::query()->where('work_assignment_id', $original->id)->count(), 'one absence, not a new one');
        $this->assertSame(
            ['absence_started', 'replacement_started', 'replacement_ended', 'replacement_started'],
            AssignmentCycleEvent::query()->where('work_assignment_id', $original->id)->orderBy('id')->pluck('event_type')->all(),
        );

        $return = $this->giveBack($secondId, '2026-10-25')->assertOk();
        $this->assertSame($original->id, $return->json('data.returned.id'));
        $this->assertSame('2026-10-25', $this->absences($original)[0]['returned_on']);

        // Every October day is paid once: the holder 11 + 7 days, the first cover 8, the second cover 5 ($10 a day).
        $items = $this->october();
        $this->assertSame([18000, 8000, 5000], [$items[$original->id]->gross_cents, $items[$firstId]->gross_cents, $items[$secondId]->gross_cents]);
        $this->assertSame(31000, collect($items)->sum('gross_cents'));
    }

    public function test_a_permanent_successor_cannot_replace_a_covering_replacement_or_an_away_holder(): void
    {
        $original = $this->original();
        $firstId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $permanent = fn (int $assignmentId) => $this->as($this->admin)->postJson($this->url()."/{$assignmentId}/replace", [
            'replacement_employee_id' => $this->makeEmployee()->id, 'replacement_date' => '2026-10-20', 'original_last_day' => '2026-10-19',
            'assignment_type' => 'permanent', 'position_id' => $this->cleaner->id, 'salary_amount' => 280, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ]);

        $permanent($firstId)->assertUnprocessable()->assertJsonPath('error_code', 'REPLACEMENT_COVERS_AN_ABSENCE');
        $permanent($original->id)->assertUnprocessable()->assertJsonPath('error_code', 'ORIGINAL_IS_AWAY');
        $this->assertSame('active', $original->fresh()->status->value);
        $this->assertSame('active', EmployeeWorkAssignment::query()->findOrFail($firstId)->status->value);
    }

    public function test_leave_and_absent_marks_inside_the_absence_cost_the_away_holder_nothing_but_working_days_still_do(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original, null, '2026-10-11', '2026-10-12')->assertOk()->json('data.replacement.id');
        $this->giveBack($replacementId, '2026-10-21')->assertOk();
        $employeeId = $original->employee_id;

        // Away Oct 12-20: an approved unpaid leave (14-16) and an absent mark (18) on days the holder is not paid for anyway.
        EmployeeLeave::query()->create(['employee_id' => $employeeId, 'leave_type' => 'unpaid', 'approval_status' => 'approved', 'start_date' => '2026-10-14', 'end_date' => '2026-10-16', 'recorded_by' => Admin::factory()->create()->id, 'decided_by' => Admin::factory()->create()->id, 'decided_at' => now()]);
        EmployeeAttendance::query()->create(['employee_id' => $employeeId, 'date' => '2026-10-18', 'status' => 'absent']);

        $line = $this->october()[$original->id];
        $this->assertSame(22000, $line->gross_cents, 'Oct 1-11 and 21-31 = 22 days');
        $this->assertSame(0, $line->other_deduction_cents, 'the away days cannot be taken twice');
        $this->assertSame(22000, $line->net_cents);

        // The same mark on a day the holder does work (Oct 25) still costs one day.
        EmployeeAttendance::query()->create(['employee_id' => $employeeId, 'date' => '2026-10-25', 'status' => 'absent']);
        $line = $this->october()[$original->id];
        $this->assertSame(1000, $line->other_deduction_cents);
        $this->assertSame(21000, $line->net_cents);
    }

    public function test_a_temporary_cover_on_days_the_holder_is_not_paid_is_not_charged_to_the_holder(): void
    {
        $original = $this->original();
        // Last working day Oct 11, the Replacement only starts Oct 15: Oct 12-14 are a gap nobody is paid for.
        $replacementId = $this->replace($original, null, '2026-10-11', '2026-10-15')->assertOk()->json('data.replacement.id');
        $this->assertNotNull($replacementId);

        $cover = function (string $from, string $to) use ($original): void {
            $temp = $this->makeEmployee('active', 'temporary');
            TemporaryReplacement::query()->create([
                'work_assignment_id' => $original->id, 'replacement_employee_id' => $temp->id, 'start_date' => $from, 'end_date' => $to,
                'currency' => 'USD', 'status' => 'active', 'approved_at' => now(),
            ]);
        };
        $cover('2026-10-12', '2026-10-14'); // inside the gap: the holder is not paid, so no cost
        $cover('2026-10-08', '2026-10-09'); // while the holder was working: two days

        $line = $this->october()[$original->id];
        $this->assertSame(2000, $line->replacement_deduction_cents);
    }

    public function test_days_nobody_covers_are_reported(): void
    {
        $original = $this->original();
        $this->replace($original, null, '2026-10-11', '2026-10-15')->assertOk();
        $this->october();

        $warnings = PayrollRun::query()->where('period', '2026-10')->firstOrFail()->totals['warnings'] ?? [];
        $this->assertNotEmpty(array_filter($warnings, fn (string $w): bool => str_contains($w, 'no Replacement works the position on 3 day(s) of 2026-10') && str_contains($w, 'nobody is paid')), json_encode($warnings));
    }

    public function test_the_replacement_stays_in_the_company_and_workplace_of_the_position_through_a_swap_and_the_return_and_cannot_be_made_from_another_company(): void
    {
        $site = $this->makeClientLocation($this->company, 'Main Site', 5);
        $otherSite = $this->makeClientLocation($this->company, 'Second Site', 5);
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, $site, 310, '2026-10-01');
        $original->update(['position_id' => $this->cleaner->id]);

        $firstId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $secondId = $this->replace(EmployeeWorkAssignment::query()->findOrFail($firstId), null, '2026-10-19', '2026-10-20')->assertOk()->json('data.replacement.id');
        $this->giveBack($secondId, '2026-10-25')->assertOk();

        foreach ([$firstId, $secondId] as $id) {
            $row = EmployeeWorkAssignment::query()->findOrFail($id);
            $this->assertSame($this->company->id, $row->client_company_id);
            $this->assertSame($site->id, $row->work_location_id, 'the Replacement works the same Workplace as the position, never another one of the Company');
        }
        $this->assertSame($site->id, $original->fresh()->work_location_id);
        $this->assertSame(0, EmployeeWorkAssignment::query()->where('work_location_id', $otherSite->id)->count());

        // Another Company's screen cannot be used to replace this Company's position.
        $elsewhere = $this->makeCompany('Another Company');
        $this->as($this->admin)->postJson("/api/v1/admin/hr/client-companies/{$elsewhere->id}/employees/{$original->id}/replace", [
            'replacement_employee_id' => $this->makeEmployee()->id, 'replacement_date' => '2026-11-10', 'original_last_day' => '2026-11-09', 'assignment_type' => 'replacement',
        ])->assertStatus(404);
        $this->assertDatabaseCount('assignment_absences', 1);
    }

    public function test_separation_details_are_shown_to_candidate_searchers_only_when_they_can_act_on_them(): void
    {
        $inactive = $this->makeEmployee('inactive');
        EmployeeSeparation::query()->create(['employee_id' => $inactive->id, 'reason' => 'other', 'separation_date' => '2026-09-15', 'rehire_eligible' => false, 'notes' => 'x', 'separated_by' => Admin::query()->value('id')]);
        $find = fn (string $token): array => collect($this->as($token)->getJson($this->url('employee-candidates').'?q='.$inactive->employee_number)->assertOk()->json('data'))->firstWhere('id', $inactive->id);

        $this->assertFalse($find($this->admin)['rehire_eligible']);
        $this->assertNull($find($this->tokenWithOnly(['hr.view']))['rehire_eligible']);
    }
}
