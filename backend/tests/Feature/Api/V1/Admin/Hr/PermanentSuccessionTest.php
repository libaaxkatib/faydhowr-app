<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\AssignmentSuccession;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollRun;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * docs/HR_PAYROLL_RULES.md N1-N4: removing a Permanent employee from an assignment needs a replacement choice - a Temporary employee who has been
 * covering that position, or a person from the HR Waiting List - and nothing else is offered. A Temporary chosen becomes Permanent (same employee
 * record) with the departing salary, from the day AFTER the last paid day; a Waiting List person is appointed through the normal status workflow.
 * Everything happens in one transaction. Transfer stays "Stop, then Assign". Synthetic data only.
 */
class PermanentSuccessionTest extends TestCase
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

    /** @return array{0: Employee, 1: EmployeeWorkAssignment} */
    private function position(int $dollars = 300, string $company = 'Test Company'): array
    {
        $permanent = $this->makeEmployee('active', 'permanent');

        return [$permanent, $this->makeAssignment($permanent, $this->makeCompany($company.' '.fake()->unique()->numerify('###')), null, $dollars, '2026-01-01')];
    }

    private function cover(EmployeeWorkAssignment $assignment, ?Employee $temp = null, string $start = '2026-09-01'): TemporaryReplacement
    {
        return TemporaryReplacement::query()->create([
            'work_assignment_id' => $assignment->id,
            'replacement_employee_id' => ($temp ?? $this->makeEmployee('active', 'temporary'))->id,
            'start_date' => $start, 'currency' => 'USD', 'status' => 'active',
        ]);
    }

    private function stopUrl(EmployeeWorkAssignment $a): string
    {
        return "/api/v1/admin/hr/client-companies/{$a->client_company_id}/employees/{$a->id}/stop";
    }

    /** @param  array<string, mixed>|null  $successor */
    private function stop(string $token, EmployeeWorkAssignment $a, string $lastDay = '2026-10-15', ?array $successor = null)
    {
        return $this->withToken($token)->postJson($this->stopUrl($a), ['end_date' => $lastDay, 'reason' => 'Left the position'] + ($successor === null ? [] : ['successor' => $successor]));
    }

    private function temporaryChoice(Employee $temp): array
    {
        return ['type' => 'temporary', 'employee_id' => $temp->id];
    }

    private function assertUntouched(EmployeeWorkAssignment $a): void
    {
        $a->refresh();
        $this->assertSame('active', $a->status->value);
        $this->assertNull($a->end_date);
        $this->assertSame(0, AssignmentSuccession::query()->count());
        $this->assertSame(0, EmployeeWorkAssignment::query()->where('replaces_assignment_id', $a->id)->count(), 'no successor assignment was created');
    }

    // ------------------------------------------------------------------ N1: the choice is mandatory

    public function test_a_permanent_assignment_cannot_be_stopped_without_a_replacement_choice(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position();
        $employees = Employee::query()->count();

        $this->stop($token, $a)->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_REQUIRED');

        $this->assertUntouched($a);
        $this->assertSame($employees, Employee::query()->count());
        $this->assertSame('active', $permanent->fresh()->status->value);
    }

    public function test_only_a_temporary_who_covered_the_position_or_a_waiting_list_person_can_be_chosen(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position();
        $coveringTemp = $this->makeEmployee('active', 'temporary');
        $this->cover($a, $coveringTemp);
        $strangerTemp = $this->makeEmployee('active', 'temporary');
        $otherPermanent = $this->makeEmployee('active', 'permanent');
        $activeNotWaiting = $this->makeEmployee('active', 'permanent');
        $typesBefore = Employee::query()->pluck('employment_type', 'id')->all();

        // A kind that is not one of the two permitted ones is refused by validation.
        $this->stop($token, $a, '2026-10-15', ['type' => 'any_active_employee', 'employee_id' => $activeNotWaiting->id])->assertStatus(422);
        // A Permanent employee is not a Temporary: Permanent -> Temporary is never part of this flow.
        $this->stop($token, $a, '2026-10-15', $this->temporaryChoice($otherPermanent))->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_NOT_TEMPORARY');
        // A Temporary who never covered THIS position.
        $this->stop($token, $a, '2026-10-15', $this->temporaryChoice($strangerTemp))->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_NOT_COVERING');
        // An employee who is not on the Waiting List.
        $this->stop($token, $a, '2026-10-15', ['type' => 'waiting_list', 'employee_id' => $activeNotWaiting->id, 'salary_amount' => 200, 'salary_currency' => 'USD'])
            ->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_NOT_WAITING');
        // Nobody replaces themselves.
        $this->stop($token, $a, '2026-10-15', ['type' => 'waiting_list', 'employee_id' => $permanent->id, 'salary_amount' => 200, 'salary_currency' => 'USD'])
            ->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_SAME_EMPLOYEE');

        $this->assertUntouched($a);
        $this->assertSame($typesBefore, Employee::query()->pluck('employment_type', 'id')->all(), 'no employment type was changed by any refusal');
    }

    public function test_the_end_endpoint_and_the_end_date_edit_demand_the_choice_too(): void
    {
        [, $token] = $this->superAdminToken();
        [, $viaEnd] = $this->position();
        [, $viaEdit] = $this->position();
        [, $alreadyPlanned] = $this->position();
        $alreadyPlanned->update(['end_date' => '2026-11-30']);

        // End
        $this->withToken($token)->patchJson("/api/v1/admin/hr/work-assignments/{$viaEnd->id}/end", ['end_date' => '2026-10-15'])->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_REQUIRED');
        $this->assertSame('active', $viaEnd->fresh()->status->value);
        $this->withToken($token)->patchJson("/api/v1/admin/hr/work-assignments/{$viaEnd->id}/end", ['end_date' => '2026-10-15', 'successor' => $this->waitingSuccessor()])->assertOk();
        $this->assertSame('end', AssignmentSuccession::query()->where('departing_assignment_id', $viaEnd->id)->value('trigger'));

        // Editing the end date: setting one where there was none is a removal.
        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$viaEdit->id}", ['end_date' => '2026-11-30'])->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_REQUIRED');
        $this->assertNull($viaEdit->fresh()->end_date);
        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$viaEdit->id}", ['end_date' => '2026-11-30', 'successor' => $this->waitingSuccessor()])->assertOk();
        $this->assertSame('edit_end_date', AssignmentSuccession::query()->where('departing_assignment_id', $viaEdit->id)->value('trigger'));
        $this->assertSame('2026-12-01', AssignmentSuccession::query()->where('departing_assignment_id', $viaEdit->id)->firstOrFail()->successor_start_date->toDateString());

        // Moving an end date LATER ends nothing new: no choice. Moving it EARLIER shortens the assignment: a choice.
        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$alreadyPlanned->id}", ['end_date' => '2026-12-31'])->assertOk();
        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$alreadyPlanned->id}", ['end_date' => '2026-11-15'])->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_REQUIRED');
        $this->assertSame('2026-12-31', $alreadyPlanned->fresh()->end_date->toDateString());
    }

    public function test_a_separation_demands_a_choice_for_every_open_permanent_assignment_and_is_all_or_nothing(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $a = $this->makeAssignment($employee, $this->makeCompany('Co A'), null, 300, '2026-01-01');
        $b = $this->makeAssignment($employee, $this->makeCompany('Co B'), null, 100, '2026-03-01');
        $body = ['reason' => 'resigned', 'separation_date' => '2026-10-10', 'rehire_eligible' => true];

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", $body)
            ->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_REQUIRED')->assertJsonPath('errors.assignment_ids', [$a->id, $b->id]);
        // One of the two is not enough, and nothing is left half done.
        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", $body + ['successors' => [['assignment_id' => $a->id] + $this->waitingSuccessor()]])
            ->assertStatus(422)->assertJsonPath('errors.assignment_ids', [$b->id]);
        $this->assertSame('active', $employee->fresh()->status->value);
        $this->assertSame(0, EmployeeSeparation::query()->count());
        $this->assertSame(0, AssignmentSuccession::query()->count());
        $this->assertSame(['active', 'active'], [$a->fresh()->status->value, $b->fresh()->status->value]);

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", $body + ['successors' => $this->successorsFor($employee)])->assertOk();
        $this->assertSame('inactive', $employee->fresh()->status->value);
        $this->assertSame(2, AssignmentSuccession::query()->where('trigger', 'separation')->count());
        $this->assertSame('2026-10-11', AssignmentSuccession::query()->where('departing_assignment_id', $a->id)->firstOrFail()->successor_start_date->toDateString());
    }

    public function test_a_cancelled_mistake_and_a_replacement_type_assignment_need_no_choice(): void
    {
        [, $token] = $this->superAdminToken();
        [, $mistake] = $this->position();
        [, $replacementType] = $this->position();
        $replacementType->update(['assignment_type' => 'replacement']);

        // Cancel is only possible for an assignment with no payroll and no covers - a mistake, not a position in service.
        $this->withToken($token)->patchJson("/api/v1/admin/hr/work-assignments/{$mistake->id}/cancel", ['reason' => 'Entered by mistake'])->assertOk();
        $this->assertSame('cancelled', $mistake->fresh()->status->value);
        // The rule is about PERMANENT positions.
        $this->withToken($token)->patchJson("/api/v1/admin/hr/work-assignments/{$replacementType->id}/end", ['end_date' => '2026-10-15'])->assertOk();
        $this->assertSame(0, AssignmentSuccession::query()->count());
    }

    // ------------------------------------------------------------------ N2 / N7: Temporary becomes Permanent

    public function test_the_temporary_who_covered_the_position_becomes_permanent_with_the_departing_salary_without_a_duplicate_employee(): void
    {
        [$admin, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position(450);
        $temp = $this->makeEmployee('active', 'temporary');
        $cover = $this->cover($a, $temp);
        $employees = Employee::query()->count();
        $tempNumber = $temp->employee_number;

        $this->stop($token, $a, '2026-10-15', $this->temporaryChoice($temp))->assertOk();

        // Same person, same record, now Permanent.
        $this->assertSame($employees, Employee::query()->count(), 'no duplicate employee');
        $temp->refresh();
        $this->assertSame($tempNumber, $temp->employee_number);
        $this->assertSame('permanent', $temp->employment_type);
        $this->assertSame('active', $temp->status->value);

        // The departing assignment ends on the last paid day and is otherwise untouched.
        $a->refresh();
        $this->assertSame('ended', $a->status->value);
        $this->assertSame('2026-10-15', $a->end_date->toDateString());
        $this->assertSame(1, $a->salaryHistories()->count());
        $this->assertSame('450.00', (string) $a->salary_amount);

        // The new Permanent assignment: next day, same company and position, the departing salary, nothing typed by HR.
        $new = EmployeeWorkAssignment::query()->where('employee_id', $temp->id)->sole();
        $this->assertSame('2026-10-16', $new->start_date->toDateString());
        $this->assertSame('permanent', $new->assignment_type->value);
        $this->assertSame($a->id, $new->replaces_assignment_id);
        $this->assertSame($a->client_company_id, $new->client_company_id);
        $this->assertSame($a->position_id, $new->position_id);
        $this->assertSame(['450.00', 'USD', 'monthly'], [(string) $new->salary_amount, $new->salary_currency, $new->salary_frequency->value]);
        $this->assertSame(['2026-10-16', '450.00'], [$new->salaryHistories()->sole()->effective_from->toDateString(), (string) $new->salaryHistories()->sole()->amount]);

        // The Temporary cover is kept as history and ends with the position.
        $cover->refresh();
        $this->assertSame('ended', $cover->status->value);
        $this->assertSame('2026-10-15', $cover->end_date->toDateString());
        $this->assertSame(1, TemporaryReplacement::query()->count());

        // The choice and the change are recorded: who, when, from/to, salary, assignments.
        $row = AssignmentSuccession::query()->sole();
        $this->assertSame(['temporary', $temp->id, $new->id, $cover->id, 'stop', $admin->id], [$row->successor_type, $row->successor_employee_id, $row->successor_assignment_id, $row->temporary_replacement_id, $row->trigger, $row->chosen_by]);
        $this->assertSame(['temporary', 'permanent', '450.00', 'inherited'], [$row->employment_type_from, $row->employment_type_to, (string) $row->salary_amount, $row->salary_source]);
        $log = DB::table('audit_logs')->where('entity_type', Employee::class)->where('entity_id', $temp->id)->where('description', 'like', '%became Permanent%')->first();
        $this->assertNotNull($log);
        $meta = json_decode($log->metadata, true);
        $this->assertSame(['from' => 'temporary', 'to' => 'permanent'], $meta['employment_type']);
        $this->assertSame(['2026-10-16', $a->id, $new->id], [$meta['effective_date'], $meta['departing_assignment_id'], $meta['successor_assignment_id']]);
        $this->assertSame('450.00', $meta['new_salary']['amount']);
        $this->assertSame($admin->id, (int) $log->admin_id);
        $this->assertSame('permanent', $permanent->fresh()->employment_type, 'the departing employee is not reclassified');
    }

    public function test_the_new_permanent_starts_the_day_after_the_last_paid_day_across_month_year_and_leap_boundaries(): void
    {
        [, $token] = $this->superAdminToken();

        foreach (['2026-10-31' => '2026-11-01', '2026-12-31' => '2027-01-01', '2028-02-28' => '2028-02-29', '2027-02-28' => '2027-03-01', '2026-10-20' => '2026-10-21'] as $lastDay => $expectedStart) {
            [, $a] = $this->position();
            $temp = $this->makeEmployee('active', 'temporary');
            $this->cover($a, $temp);

            $this->stop($token, $a, $lastDay, $this->temporaryChoice($temp))->assertOk();

            $new = EmployeeWorkAssignment::query()->where('employee_id', $temp->id)->sole();
            $this->assertSame($expectedStart, $new->start_date->toDateString(), "last day {$lastDay}");
            $this->assertSame($lastDay, $a->fresh()->end_date->toDateString());
            $this->assertTrue($new->start_date->gt($a->fresh()->end_date), 'never on or before the last paid day: no day is paid twice');
        }
    }

    public function test_the_inherited_salary_is_the_one_in_effect_on_the_last_day_and_a_typed_salary_is_ignored(): void
    {
        [, $token] = $this->superAdminToken();
        [, $changed] = $this->position(300);
        $changed->salaryHistories()->create(['effective_from' => '2026-10-10', 'amount' => 350, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'raise']);
        $changed->update(['salary_amount' => 350]);
        [, $before] = $this->position(300);
        $before->salaryHistories()->create(['effective_from' => '2026-10-10', 'amount' => 350, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'raise']);
        $tempA = $this->makeEmployee('active', 'temporary');
        $tempB = $this->makeEmployee('active', 'temporary');
        $this->cover($changed, $tempA);
        $this->cover($before, $tempB);

        // Last day AFTER the raise -> 350; last day BEFORE it -> 300 (a Permanent successor is not the month-wide Replacement rule).
        $this->stop($token, $changed, '2026-10-15', $this->temporaryChoice($tempA) + ['salary_amount' => 999, 'salary_currency' => 'EUR'])->assertOk();
        $this->stop($token, $before, '2026-10-05', $this->temporaryChoice($tempB))->assertOk();

        $this->assertSame(['350.00', 'USD'], [(string) EmployeeWorkAssignment::query()->where('employee_id', $tempA->id)->sole()->salary_amount, EmployeeWorkAssignment::query()->where('employee_id', $tempA->id)->sole()->salary_currency]);
        $this->assertSame('300.00', (string) EmployeeWorkAssignment::query()->where('employee_id', $tempB->id)->sole()->salary_amount);
        // The old salary history is untouched.
        $this->assertSame([300.0, 350.0], $changed->salaryHistories()->orderBy('effective_from')->pluck('amount')->map(fn ($v) => (float) $v)->all());
    }

    public function test_a_separation_can_name_the_temporary_who_takes_over(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position(300);
        $temp = $this->makeEmployee('active', 'temporary');
        $this->cover($a, $temp);

        $this->withToken($token)->patchJson("/api/v1/admin/hr/employees/{$permanent->id}/separate", [
            'reason' => 'resigned', 'separation_date' => '2026-10-10', 'rehire_eligible' => true,
            'successors' => [['assignment_id' => $a->id] + $this->temporaryChoice($temp)],
        ])->assertOk();

        $this->assertSame('inactive', $permanent->fresh()->status->value);
        $this->assertSame('permanent', $temp->fresh()->employment_type);
        $this->assertSame('2026-10-11', EmployeeWorkAssignment::query()->where('employee_id', $temp->id)->sole()->start_date->toDateString());
        $this->assertSame('ended', TemporaryReplacement::query()->sole()->status->value);
    }

    // ------------------------------------------------------------------ N3 / N8: the Waiting List

    public function test_a_waiting_list_person_is_appointed_and_assigned_in_one_workflow_with_the_salary_hr_enters(): void
    {
        [, $token] = $this->superAdminToken();
        [, $a] = $this->position(300);
        $waiting = $this->makeEmployee('waiting');
        $employees = Employee::query()->count();

        $this->stop($token, $a, '2026-10-15', ['type' => 'waiting_list', 'employee_id' => $waiting->id, 'salary_amount' => 275, 'salary_currency' => 'USD'])->assertOk();

        $this->assertSame($employees, Employee::query()->count(), 'no duplicate employee');
        $this->assertSame('active', $waiting->fresh()->status->value);
        $this->assertSame('permanent', $waiting->fresh()->employment_type);
        $this->assertSame(1, EmployeeStatusHistory::query()->where('employee_id', $waiting->id)->where('from_status', 'waiting')->where('to_status', 'active')->count());
        $new = EmployeeWorkAssignment::query()->where('employee_id', $waiting->id)->sole();
        $this->assertSame(['2026-10-16', $a->id, '275.00'], [$new->start_date->toDateString(), $new->replaces_assignment_id, (string) $new->salary_amount], 'the salary is the one HR entered, not assumed to be inherited');
        $row = AssignmentSuccession::query()->sole();
        $this->assertSame(['waiting_list', 'entered', null], [$row->successor_type, $row->salary_source, $row->temporary_replacement_id]);
    }

    public function test_a_waiting_list_person_needs_a_salary(): void
    {
        [, $token] = $this->superAdminToken();
        [, $a] = $this->position();
        $waiting = $this->makeEmployee('waiting');

        $this->stop($token, $a, '2026-10-15', ['type' => 'waiting_list', 'employee_id' => $waiting->id])->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_SALARY_REQUIRED');

        $this->assertUntouched($a);
        $this->assertSame('waiting', $waiting->fresh()->status->value);
    }

    public function test_an_incomplete_registration_is_refused_and_nothing_is_left_behind(): void
    {
        $hr = $this->tokenWithOnly(['hr.view', 'hr.manage']);
        [, $a] = $this->position();
        $waiting = $this->makeEmployee('waiting');
        DB::table('employees')->where('id', $waiting->id)->update(['registration_origin' => 'new']);

        $this->stop($hr, $a, '2026-10-15', ['type' => 'waiting_list', 'employee_id' => $waiting->id, 'salary_amount' => 200, 'salary_currency' => 'USD'])->assertStatus(422);

        $this->assertUntouched($a);
        $this->assertSame('waiting', $waiting->fresh()->status->value);
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $waiting->id)->where('to_status', 'active')->count());
        $this->assertSame(0, EmployeeWorkAssignment::query()->where('employee_id', $waiting->id)->count());
    }

    public function test_a_failure_late_in_the_workflow_undoes_the_ending_and_the_appointment(): void
    {
        [, $token] = $this->superAdminToken();
        [, $a] = $this->position();
        $waiting = $this->makeEmployee('waiting');
        // The person already works elsewhere in the same period and the multi-company policy is OFF: creating the new assignment fails
        // AFTER the departing assignment was ended and the person was appointed. Everything must roll back.
        $this->makeAssignment($waiting, $this->makeCompany('Elsewhere'), null, 100, '2026-01-01');

        $this->stop($token, $a, '2026-10-15', ['type' => 'waiting_list', 'employee_id' => $waiting->id, 'salary_amount' => 200, 'salary_currency' => 'USD'])
            ->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_ELSEWHERE');

        $a->refresh();
        $this->assertSame('active', $a->status->value);
        $this->assertNull($a->end_date);
        $this->assertSame('waiting', $waiting->fresh()->status->value);
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $waiting->id)->where('to_status', 'active')->count());
        $this->assertSame(0, AssignmentSuccession::query()->count());
    }

    // ------------------------------------------------------------------ N4: transfer = Stop, then Assign

    public function test_a_transfer_is_stop_then_assign_and_the_old_assignment_is_preserved_not_overwritten(): void
    {
        [$admin, $token] = $this->superAdminToken();
        [$permanent, $old] = $this->position(300, 'Old Co');
        $old->salaryHistories()->create(['effective_from' => '2026-06-01', 'amount' => 320, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'raise']);
        $newCompany = $this->makeCompany('New Co');

        $this->stop($token, $old, '2026-10-12', $this->waitingSuccessor())->assertOk();
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$permanent->id}/work-assignments", [
            'client_company_id' => $newCompany->id, 'start_date' => '2026-10-13', 'salary_amount' => 400, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertCreated();

        $assignments = EmployeeWorkAssignment::query()->where('employee_id', $permanent->id)->orderBy('id')->get();
        $this->assertCount(2, $assignments);
        $kept = $assignments[0];
        $this->assertSame($old->id, $kept->id, 'the old assignment is the same row');
        $this->assertSame(['ended', '2026-10-12', $old->client_company_id, '2026-01-01'], [$kept->status->value, $kept->end_date->toDateString(), $kept->client_company_id, $kept->start_date->toDateString()]);
        $this->assertSame([300.0, 320.0], $kept->salaryHistories()->orderBy('effective_from')->pluck('amount')->map(fn ($v) => (float) $v)->all(), 'salary history is intact');
        $this->assertSame([$newCompany->id, '2026-10-13'], [$assignments[1]->client_company_id, $assignments[1]->start_date->toDateString()]);
        $this->assertGreaterThan(0, DB::table('audit_logs')->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $old->id)->where('admin_id', $admin->id)->count(), 'the audit trail of the old assignment is kept');
    }

    // ------------------------------------------------------------------ one choice per assignment, options, permissions

    public function test_a_position_is_removed_once_and_its_choice_is_recorded_once(): void
    {
        [, $token] = $this->superAdminToken();
        [, $a] = $this->position();

        $this->stop($token, $a, '2026-10-15', $this->waitingSuccessor())->assertOk();
        $this->stop($token, $a, '2026-10-16', $this->waitingSuccessor())->assertStatus(422)->assertJsonPath('error_code', 'ASSIGNMENT_NOT_ACTIVE');

        $this->assertSame(1, AssignmentSuccession::query()->count());
        $this->assertSame(2, EmployeeWorkAssignment::query()->count(), 'the departing one and exactly one successor');
        $this->expectException(QueryException::class);
        AssignmentSuccession::query()->create(['departing_assignment_id' => $a->id, 'successor_type' => 'waiting_list', 'successor_employee_id' => $this->makeEmployee('waiting')->id, 'departing_last_day' => '2026-10-15', 'successor_start_date' => '2026-10-16', 'trigger' => 'stop']);
    }

    public function test_the_options_list_only_the_two_permitted_kinds_and_no_salary(): void
    {
        $hr = $this->tokenWithOnly(['hr.view', 'hr.manage']);
        [, $a] = $this->position(777);
        $covering = $this->makeEmployee('active', 'temporary');
        $this->cover($a, $covering);
        $this->makeEmployee('active', 'temporary'); // a Temporary who never covered this position
        $this->makeEmployee('active', 'permanent');
        $waiting = $this->makeEmployee('waiting');
        $this->makeEmployee('waiting');

        $response = $this->withToken($hr)->getJson("/api/v1/admin/hr/work-assignments/{$a->id}/successor-options?q=".urlencode($waiting->employee_number))->assertOk();

        $this->assertTrue($response->json('data.choice_required'));
        $this->assertSame([$covering->id], array_column($response->json('data.temporary'), 'employee_id'));
        $this->assertSame([$waiting->id], array_column($response->json('data.waiting_list'), 'employee_id'));
        $this->assertStringNotContainsString('salary', $response->getContent(), 'no salary is returned');
    }

    public function test_only_users_who_may_manage_hr_can_end_a_position_or_see_the_choices(): void
    {
        [, $a] = $this->position();
        $viewer = $this->tokenWithOnly(['hr.view']);
        $nobody = $this->noPermissionToken();

        foreach ([$viewer, $nobody] as $token) {
            $this->app['auth']->forgetGuards(); // each token is a different user
            $this->withToken($token)->patchJson("/api/v1/admin/hr/work-assignments/{$a->id}/end", ['end_date' => '2026-10-15', 'successor' => $this->waitingSuccessor()])->assertForbidden();
            $this->withToken($token)->getJson("/api/v1/admin/hr/work-assignments/{$a->id}/successor-options")->assertForbidden();
            $this->stop($token, $a, '2026-10-15', $this->waitingSuccessor())->assertForbidden();
        }

        $this->assertUntouched($a);
    }

    // ------------------------------------------------------------------ U9: employment type now, payroll from the effective date

    /** @return array<string, mixed> the employee's payroll line of the month */
    private function payrollLine(string $token, string $period, Employee $employee): array
    {
        $runId = $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated()->json('data.id');

        return collect($this->withToken($token)->getJson("/api/v1/admin/hr/payroll-runs/{$runId}")->json('data.items'))->firstWhere('employee_id', $employee->id) ?? [];
    }

    public function test_the_effective_date_is_inclusive_and_is_never_shifted_to_the_next_day(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position(310);
        $temp = $this->makeEmployee('active', 'temporary');
        $this->cover($a, $temp);

        // The departing employee's last paid day is 9 October; HR selects 10 October as the effective date.
        $this->stop($token, $a, '2026-10-09', $this->temporaryChoice($temp) + ['effective_date' => '2026-10-10'])->assertOk();

        $new = EmployeeWorkAssignment::query()->where('employee_id', $temp->id)->sole();
        $this->assertSame('2026-10-10', $new->start_date->toDateString(), 'not moved to 11 October');
        $this->assertSame('2026-10-10', $new->salaryHistories()->sole()->effective_from->toDateString());
        $this->assertSame(['2026-10-09', '2026-10-10'], [$a->fresh()->end_date->toDateString(), AssignmentSuccession::query()->sole()->successor_start_date->toDateString()]);
        $meta = json_decode(DB::table('audit_logs')->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $a->id)->where('description', 'like', 'Replacement chosen%')->value('metadata'), true);
        $this->assertSame(['2026-10-10', true], [$meta['effective_date'], $meta['effective_date_entered']]);

        // October: 10 October is the first day of the new Permanent (22 days of 31 = 220.00); the former Permanent keeps 1-9 October (9 days = 90.00).
        $this->assertSame('220.00', $this->payrollLine($token, '2026-10', $temp->fresh())['gross'], '10 October is included: 22 days, not 21');
        $this->assertSame('90.00', $this->payrollLine($token, '2026-10', $permanent)['gross']);
    }

    public function test_the_employment_type_changes_when_the_selection_is_saved_but_pay_starts_only_on_the_effective_date(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position(300);
        $temp = $this->makeEmployee('active', 'temporary');
        $cover = $this->cover($a, $temp);

        // Saved on 20 October; the departing employee's last day is 31 October and the effective date is 10 November (1-9 November uncovered).
        $this->stop($token, $a, '2026-10-31', $this->temporaryChoice($temp) + ['effective_date' => '2026-11-10'])->assertOk();

        // The record shows Permanent immediately; it is still the same employee.
        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$temp->id}")->assertOk()->assertJsonPath('data.employment_type', 'permanent')->assertJsonPath('data.id', $temp->id);
        $this->assertSame(1, Employee::query()->where('employee_number', $temp->employee_number)->count());

        // But the assignment and the salary begin on 10 November: no October pay for the new Permanent, and November counts from the 10th (21 days of 30).
        $new = EmployeeWorkAssignment::query()->where('employee_id', $temp->id)->sole();
        $this->assertSame('2026-11-10', $new->start_date->toDateString(), 'a later effective date is not backdated');
        $this->assertSame([], $this->payrollLine($token, '2026-10', $temp->fresh()), 'no line for the new Permanent before the effective date');
        $this->assertSame('210.00', $this->payrollLine($token, '2026-11', $temp->fresh())['gross']);
        // The gap (1-9 November) is paid to nobody: the former Permanent has no line in November and the successor starts on the 10th.
        $this->assertSame([], $this->payrollLine($token, '2026-11', $permanent));

        // The former Permanent still carries the cover's cost in October, and the cover and the history are intact.
        $this->assertSame('ended', $cover->fresh()->status->value);
        $line = $this->payrollLine($token, '2026-10', $permanent);
        $this->assertSame('300.00', $line['gross']);
        $this->assertSame('permanent', $temp->fresh()->employment_type, 'the type flag never changes what was calculated for earlier periods');
    }

    public function test_an_effective_date_on_or_before_the_last_paid_day_is_refused_not_moved(): void
    {
        [, $token] = $this->superAdminToken();
        [, $a] = $this->position();
        $temp = $this->makeEmployee('active', 'temporary');
        $this->cover($a, $temp);

        foreach (['2026-10-09', '2026-10-08'] as $effective) {
            $this->stop($token, $a, '2026-10-09', $this->temporaryChoice($temp) + ['effective_date' => $effective])
                ->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_EFFECTIVE_DATE_NOT_AFTER_LAST_DAY');
        }

        $this->assertUntouched($a);
        $this->assertSame('temporary', $temp->fresh()->employment_type, 'a refused selection changes nothing');
        $this->stop($token, $a, '2026-10-09', $this->temporaryChoice($temp) + ['effective_date' => 'not a date'])->assertStatus(422);

        // Without an effective date it is the day after the last paid day.
        $this->stop($token, $a, '2026-10-09', $this->temporaryChoice($temp))->assertOk();
        $this->assertSame('2026-10-10', EmployeeWorkAssignment::query()->where('employee_id', $temp->id)->sole()->start_date->toDateString());
    }

    public function test_a_waiting_list_person_also_starts_on_the_chosen_effective_date(): void
    {
        [, $token] = $this->superAdminToken();
        [, $a] = $this->position();

        $this->stop($token, $a, '2026-10-15', $this->waitingSuccessor() + ['effective_date' => '2026-10-20'])->assertOk();

        $this->assertSame('2026-10-20', AssignmentSuccession::query()->sole()->successor_start_date->toDateString());
    }

    public function test_a_back_dated_effective_date_in_a_closed_month_is_a_super_admin_correction(): void
    {
        [$admin, $super] = $this->superAdminToken();
        $hr = $this->tokenWithOnly(['hr.view', 'hr.manage']);
        [, $a] = $this->position(300);
        $temp = $this->makeEmployee('active', 'temporary');
        $this->cover($a, $temp, '2026-08-01');
        PayrollRun::query()->create(['period' => '2026-09', 'status' => 'approved']);
        $body = $this->temporaryChoice($temp) + ['effective_date' => '2026-09-11'];

        // Not for an HR editor: it would change what approved September paid. Nothing is left behind.
        $this->stop($hr, $a, '2026-09-10', $body)->assertForbidden()->assertJsonPath('error_code', 'PAYROLL_CORRECTION_SUPER_ADMIN_ONLY');
        $this->app['auth']->forgetGuards();
        // A Super Admin needs a reason.
        $this->stop($super, $a, '2026-09-10', $body)->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->assertUntouched($a);
        $this->assertSame('temporary', $temp->fresh()->employment_type);

        $this->withToken($super)->postJson($this->stopUrl($a), ['end_date' => '2026-09-10', 'reason' => 'Left', 'correction_reason' => 'Appointed with effect from 11 September', 'successor' => $body])->assertOk();
        $this->assertSame('permanent', $temp->fresh()->employment_type);
        $this->assertSame('2026-09-11', EmployeeWorkAssignment::query()->where('employee_id', $temp->id)->sole()->start_date->toDateString());
    }

    /** @return array<int, array<string, mixed>> gross, net and deductions of every line of the month, by employee */
    private function payrollItems(string $token, string $period): array
    {
        $runId = $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated()->json('data.id');
        $items = [];

        foreach ($this->withToken($token)->getJson("/api/v1/admin/hr/payroll-runs/{$runId}")->json('data.items') as $item) {
            $items[$item['employee_id']] = ['gross' => $item['gross'], 'net' => $item['net'], 'deductions' => array_map(fn (array $d): array => [$d['kind'], $d['amount']], $item['deductions'])];
        }

        ksort($items);

        return $items;
    }

    public function test_converting_a_temporary_does_not_change_what_was_calculated_for_earlier_months(): void
    {
        [, $token] = $this->superAdminToken();
        [, $a] = $this->position(300);
        $temp = $this->makeEmployee('active', 'temporary');
        $this->cover($a, $temp, '2026-09-01')->update(['approved_at' => now()]);

        $before = $this->payrollItems($token, '2026-09');
        $this->stop($token, $a, '2026-10-09', $this->temporaryChoice($temp) + ['effective_date' => '2026-10-10'])->assertOk();

        // The employee record now says Permanent, yet September is calculated exactly as before: the cover cost is still deducted from the former
        // Permanent and the new Permanent (assignment from 10 October) has no line in September.
        $this->assertSame('permanent', $temp->fresh()->employment_type);
        $this->assertSame($before, $this->payrollItems($token, '2026-09'));
        $this->assertSame([['replacement', '300.00']], $before[$a->employee_id]['deductions']);
        $this->assertArrayNotHasKey($temp->id, $before);
        $this->assertArrayNotHasKey($temp->id, $this->payrollItems($token, '2026-09'));
    }

    public function test_replace_employee_still_accepts_any_active_employee(): void
    {
        [, $token] = $this->superAdminToken();
        [, $a] = $this->position(300);
        [, $b] = $this->position(300);
        // Neither is a Temporary who covered the position nor on the Waiting List: Replace Employee names its own successor and is not limited to N1's two kinds.
        $first = $this->makeEmployee('active', 'permanent');
        $second = $this->makeEmployee('active', 'permanent');

        $this->withToken($token)->postJson("/api/v1/admin/hr/client-companies/{$a->client_company_id}/employees/{$a->id}/replace", [
            'replacement_employee_id' => $first->id, 'replacement_date' => '2026-10-16', 'assignment_type' => 'permanent',
            'salary_amount' => 280, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertOk();
        $this->withToken($token)->postJson("/api/v1/admin/hr/client-companies/{$b->client_company_id}/employees/{$b->id}/replace", [
            'replacement_employee_id' => $second->id, 'replacement_date' => '2026-10-16', 'assignment_type' => 'replacement',
        ])->assertOk();

        $this->assertSame($a->id, EmployeeWorkAssignment::query()->where('employee_id', $first->id)->sole()->replaces_assignment_id);
        $this->assertSame($b->id, EmployeeWorkAssignment::query()->where('employee_id', $second->id)->sole()->replaces_assignment_id);
        $this->assertSame(0, AssignmentSuccession::query()->count(), 'Replace Employee records its own successor, not an N1 choice');
    }

    public function test_permanent_to_temporary_has_no_approved_rule_so_the_current_behaviour_is_only_recorded(): void
    {
        // U1 is UNRESOLVED (docs/HR_PAYROLL_RULES.md 5.2). This pins what the system does today so that any future rule is a deliberate change.
        [$admin, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position(300);

        $this->withToken($token)->putJson("/api/v1/admin/hr/employees/{$permanent->id}", ['employment_type' => 'temporary'])->assertOk();

        $this->assertSame('temporary', $permanent->fresh()->employment_type);
        $this->assertSame(['active', null], [$a->fresh()->status->value, $a->fresh()->end_date?->toDateString()], 'the open assignment is not touched');
        $audit = json_decode(DB::table('audit_logs')->where('entity_type', Employee::class)->where('entity_id', $permanent->id)->where('description', 'like', '%updated%')->orderByDesc('id')->value('metadata'), true);
        $this->assertSame(['from' => 'permanent', 'to' => 'temporary'], $audit['employment_type']);

        // Consequence: payroll skips the person's own assignment with a warning, so the pay line disappears.
        $run = $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated();
        $this->assertSame(0, $run->json('data.item_count'));
        $this->assertStringContainsString('Temporary employee', implode(' ', $run->json('data.warnings')));
    }
}
