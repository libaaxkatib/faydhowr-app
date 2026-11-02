<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssignmentSuccession;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision 2026-11-02: when a Permanent position is taken over, HR may choose ANY person in the HR system - the Waiting List is not required, and a
 * person is not excluded because their status or category differs or because their registration is incomplete. What was not complete is RECORDED (status
 * history, audit, and the HR compliance report), never silently blocked. The protections that remain: the Temporary must really have covered the position,
 * nobody replaces themselves, a person recorded as not eligible for rehire is not brought back here, and every check of the assignment workflow.
 * Synthetic data only.
 */
class AnyPersonSuccessorTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private Admin $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
        [$this->owner, $this->token] = $this->superAdminToken();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Employee, 1: EmployeeWorkAssignment} */
    private function position(int $dollars = 300): array
    {
        $permanent = $this->makeEmployee('active', 'permanent');

        return [$permanent, $this->makeAssignment($permanent, $this->makeCompany('Co '.fake()->unique()->numerify('###')), null, $dollars, '2026-01-01')];
    }

    private function person(string $status, string $name = 'Some Person', string $type = 'permanent'): Employee
    {
        $employee = $this->makeEmployee($status, $type);
        $employee->forceFill(['full_name' => $name])->save();

        return $employee;
    }

    private function stop(string $token, EmployeeWorkAssignment $a, array $successor, string $lastDay = '2026-10-15')
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson("/api/v1/admin/hr/client-companies/{$a->client_company_id}/employees/{$a->id}/stop", ['end_date' => $lastDay, 'reason' => 'Left the position', 'successor' => $successor]);
    }

    private function choice(Employee $e, string $type = 'person', int $salary = 200): array
    {
        return ['type' => $type, 'employee_id' => $e->id, 'salary_amount' => $salary, 'salary_currency' => 'USD'];
    }

    private function offered(EmployeeWorkAssignment $a, string $q = '', ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->getJson("/api/v1/admin/hr/work-assignments/{$a->id}/successor-options?q=".urlencode($q));
    }

    // ------------------------------------------------------------------ 1. the Waiting List still works

    public function test_a_person_on_the_waiting_list_can_still_be_selected_with_the_normal_workflow(): void
    {
        [, $a] = $this->position();
        $waiting = $this->person('waiting', 'Waiting Wanda');

        $this->stop($this->token, $a, $this->choice($waiting, 'waiting_list', 250), '2026-10-15')->assertOk();

        $this->assertSame('active', $waiting->fresh()->status->value);
        $row = AssignmentSuccession::query()->firstOrFail();
        $this->assertSame('waiting_list', $row->successor_type, 'the record says the person was on the Waiting List');
        $this->assertSame('entered', $row->salary_source);
        $this->assertSame('250.00', (string) $row->salary_amount);
        $this->assertSame('USD', $row->salary_currency);
        $this->assertSame('2026-10-16', $row->successor_start_date->toDateString());
        $successor = EmployeeWorkAssignment::query()->findOrFail($row->successor_assignment_id);
        $this->assertSame($a->id, $successor->replaces_assignment_id);
        $this->assertSame('2026-10-15', $a->fresh()->end_date->toDateString());
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $waiting->id)->where('source', 'successor_appointment_exception')->count(), 'the normal workflow sufficed: no exception');
    }

    // ------------------------------------------------------------------ 2. any other person

    public function test_a_person_outside_the_waiting_list_can_be_searched_and_chosen(): void
    {
        [, $a] = $this->position();
        $active = $this->person('active', 'Active Abdi');
        $approved = $this->person('approved', 'Approved Amina');
        $practical = $this->person('practical', 'Practical Pharah');
        $applicant = $this->person('applicant', 'Applicant Ali');

        foreach ([$active, $approved, $practical, $applicant] as $candidate) {
            $found = collect($this->offered($a, $candidate->employee_number)->assertOk()->json('data.people'))->pluck('employee_id')->all();
            $this->assertContains($candidate->id, $found, "{$candidate->status->value} person is found by employee number");
        }

        [, $second] = $this->position();
        [, $third] = $this->position();
        [, $fourth] = $this->position();

        $this->stop($this->token, $a, $this->choice($active))->assertOk();
        $this->stop($this->token, $second, $this->choice($approved))->assertOk();
        $this->stop($this->token, $third, $this->choice($practical))->assertOk();
        $this->stop($this->token, $fourth, $this->choice($applicant))->assertOk();

        foreach ([$active, $approved, $practical, $applicant] as $candidate) {
            $this->assertSame('active', $candidate->fresh()->status->value);
            $this->assertSame(1, EmployeeWorkAssignment::query()->where('employee_id', $candidate->id)->where('assignment_type', 'permanent')->count(), 'one new assignment, no duplicate profile');
        }

        $this->assertSame(['person', 'person', 'person', 'person'], AssignmentSuccession::query()->orderBy('id')->pluck('successor_type')->all());
        $this->assertSame(Employee::query()->count(), Employee::query()->distinct()->count('id'), 'no profile was duplicated');
    }

    public function test_a_waiting_list_request_for_someone_who_is_not_waiting_is_recorded_as_any_other_person(): void
    {
        [, $a] = $this->position();
        $other = $this->person('approved');

        $this->stop($this->token, $a, $this->choice($other, 'waiting_list'))->assertOk();

        $this->assertSame('person', AssignmentSuccession::query()->firstOrFail()->successor_type);
    }

    public function test_the_search_finds_people_by_name_or_employee_number_and_tells_similar_names_apart(): void
    {
        [$departing, $a] = $this->position();
        $first = $this->person('active', 'Ali Hassan Nur');
        $second = $this->person('inactive', 'Ali Hassan Omar', 'temporary');
        $this->makeAssignment($first, $this->makeCompany('Hospital X'), null, 150, '2026-02-01');

        $byName = $this->offered($a, 'ali hassan')->assertOk()->json('data.people');

        $this->assertCount(2, $byName);
        $one = collect($byName)->firstWhere('employee_id', $first->id);
        $two = collect($byName)->firstWhere('employee_id', $second->id);
        $this->assertNotSame($one['employee_number'], $two['employee_number']);
        $this->assertSame(['active', 'permanent'], [$one['status'], $one['employment_type']]);
        $this->assertSame(['inactive', 'temporary'], [$two['status'], $two['employment_type']]);
        $this->assertSame(['Hospital X'], $one['current_assignments'], 'where they work now, so two people with one name are not confused');
        $this->assertSame([], $two['current_assignments']);
        $this->assertMatchesRegularExpression('/^\.\.\.\d{3}$/', $one['phone_hint'], 'only the last three digits of the phone');
        $this->assertNotSame($one['phone_hint'], $two['phone_hint']);
        $this->assertNotContains($departing->id, array_column($byName, 'employee_id'), 'the departing employee is never offered');

        $this->assertSame([$second->id], collect($this->offered($a, strtolower($second->employee_number))->json('data.people'))->pluck('employee_id')->all(), 'by employee number, in any case');
        $this->assertSame([], $this->offered($a)->json('data.people'), 'nothing is listed without a search: the register is never dumped');
    }

    public function test_the_waiting_list_shortlist_and_the_temporary_option_are_still_offered(): void
    {
        [, $a] = $this->position();
        $waiting = $this->person('waiting', 'Waiting Zainab');
        $this->person('active', 'Not Waiting');

        $data = $this->offered($a)->assertOk()->json('data');

        $this->assertSame([$waiting->id], array_column($data['waiting_list'], 'employee_id'));
        $this->assertArrayHasKey('temporary', $data);
        $this->assertTrue($data['choice_required']);
    }

    // ------------------------------------------------------------------ 3. incomplete profile / requirements: recorded, not blocked

    public function test_a_new_registration_with_missing_steps_is_appointed_and_the_gap_is_recorded_not_blocked(): void
    {
        [, $a] = $this->position();
        $newcomer = $this->person('applicant', 'Newcomer Nasra');
        DB::table('employees')->where('id', $newcomer->id)->update(['registration_origin' => 'new']);
        $hr = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);

        $this->stop($hr, $a, $this->choice($newcomer))->assertOk();

        $this->assertSame('active', $newcomer->fresh()->status->value);
        $history = EmployeeStatusHistory::query()->where('employee_id', $newcomer->id)->where('to_status', 'active')->firstOrFail();
        $this->assertSame('successor_appointment_exception', $history->source);
        $this->assertStringContainsString('Guarantor verified', (string) $history->override_reason);
        $this->assertStringContainsString('Practical approved', (string) $history->override_reason);
        $this->assertNotNull($history->changed_by, 'the person who made the appointment is on record');
        $audit = AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $newcomer->id)->get()->first(fn (AuditLog $l): bool => ($l->metadata['event'] ?? null) === 'successor_appointment_exception');
        $this->assertNotNull($audit);
        $this->assertCount(5, $audit->metadata['registration_steps_missing']);
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('employee_id', $newcomer->id)->count(), 'the assignment exists: nothing was blocked');
    }

    public function test_the_rehire_protection_is_kept_for_a_person_recorded_as_not_eligible(): void
    {
        [, $a] = $this->position();
        $barred = $this->person('inactive', 'Barred Bashir');
        EmployeeSeparation::query()->create(['employee_id' => $barred->id, 'reason' => 'terminated', 'separation_date' => '2026-08-01', 'rehire_eligible' => false, 'separated_by' => $this->owner->id]);
        $eligible = $this->person('inactive', 'Eligible Edil');
        EmployeeSeparation::query()->create(['employee_id' => $eligible->id, 'reason' => 'resigned', 'separation_date' => '2026-08-01', 'rehire_eligible' => true, 'separated_by' => $this->owner->id]);

        $this->stop($this->token, $a, $this->choice($barred))->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_NOT_REHIRE_ELIGIBLE');

        $this->assertSame('active', $a->fresh()->status->value, 'nothing was changed by the refusal');
        $this->assertSame('inactive', $barred->fresh()->status->value);
        $this->assertSame(0, AssignmentSuccession::query()->count());

        $this->stop($this->token, $a, $this->choice($eligible))->assertOk();
        $this->assertSame('active', $eligible->fresh()->status->value);
    }

    // ------------------------------------------------------------------ 9. the existing workflow stays intact

    public function test_salary_currency_effective_date_and_self_replacement_rules_still_apply_to_any_person(): void
    {
        [$departing, $a] = $this->position();
        $someone = $this->person('active', 'Someone Said');

        $this->stop($this->token, $a, ['type' => 'person', 'employee_id' => $someone->id])->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_SALARY_REQUIRED');
        $this->stop($this->token, $a, ['type' => 'person', 'employee_id' => $someone->id, 'salary_amount' => 200, 'salary_currency' => 'US'])->assertStatus(422);
        $this->stop($this->token, $a, $this->choice($someone) + ['effective_date' => '2026-10-15'])->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_EFFECTIVE_DATE_NOT_AFTER_LAST_DAY');
        $this->stop($this->token, $a, $this->choice($departing))->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_SAME_EMPLOYEE');
        $this->stop($this->token, $a, ['type' => 'anyone', 'employee_id' => $someone->id])->assertStatus(422);
        $this->assertSame(0, AssignmentSuccession::query()->count());
        $this->assertNull($a->fresh()->end_date);

        $this->stop($this->token, $a, $this->choice($someone, 'person', 345) + ['effective_date' => '2026-10-18', 'salary_currency' => 'USD'])->assertOk();

        $row = AssignmentSuccession::query()->firstOrFail();
        $this->assertSame('345.00', (string) $row->salary_amount);
        $this->assertSame('2026-10-18', $row->successor_start_date->toDateString(), 'the effective date HR chose is kept, never moved');
        $successor = EmployeeWorkAssignment::query()->findOrFail($row->successor_assignment_id);
        $this->assertSame('345.00', (string) $successor->salary_amount);
        $this->assertSame('USD', $successor->salary_currency);
        $this->assertSame($a->position_id, $successor->position_id);
        $this->assertSame($a->client_company_id, $successor->client_company_id);
        $this->assertTrue(AuditLog::query()->get()->contains(fn (AuditLog $l): bool => ($l->metadata['event'] ?? null) === 'successor_chosen' && ($l->metadata['successor_type'] ?? null) === 'person'));
    }

    public function test_choosing_a_successor_changes_no_payroll_and_only_authorized_roles_can_do_it(): void
    {
        [, $a] = $this->position();
        $someone = $this->person('active', 'Someone Said');
        PayrollRun::query()->create(['period' => '2026-09', 'status' => 'draft', 'currency' => 'USD']);
        $payroll = fn (): array => [PayrollRun::query()->count(), PayrollItem::query()->count(), PayrollRun::query()->pluck('status', 'period')->all()];
        $before = $payroll();
        $viewOnly = $this->tokenWithOnly(['hr.view']);

        $this->stop($viewOnly, $a, $this->choice($someone))->assertForbidden();
        $this->assertSame(0, AssignmentSuccession::query()->count());

        $this->stop($this->token, $a, $this->choice($someone))->assertOk();

        $this->assertSame($before, $payroll(), 'no payroll run or line was created, changed or regenerated');
        $this->assertSame(403, $this->offered($a, 'x', $this->tokenWithOnly([]))->status());
    }
}
