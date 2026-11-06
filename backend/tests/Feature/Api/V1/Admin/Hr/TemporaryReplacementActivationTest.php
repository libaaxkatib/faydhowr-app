<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision 2026-11-03: the person who does a Temporary cover may be Active, Waiting or Applicant. Choosing a Waiting / Applicant person changes
 * nothing; only when the cover is SAVED does that person (and only that person) become Active, in the same transaction as the cover. Nothing else about
 * who may cover changes: the person must still be a Temporary employee, a Permanent's position is still what is covered, overlaps are still refused.
 * Synthetic data only.
 */
class TemporaryReplacementActivationTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $token;

    private EmployeeWorkAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
        [, $this->token] = $this->superAdminToken();
        $this->assignment = $this->makeAssignment($this->makeEmployee('active', 'permanent'), $this->makeCompany('Acme Co'), null, 300, '2026-01-01');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** A Temporary person in the given status. `existing` = registered before the registration workflow (never held by it), `new` = held by it. */
    private function person(string $status, string $origin = 'existing', string $type = 'temporary'): Employee
    {
        $employee = $this->makeEmployee($status, $type);
        DB::table('employees')->where('id', $employee->id)->update(['registration_origin' => $origin]);

        return $employee->refresh();
    }

    private function body(Employee $employee, array $over = []): array
    {
        return array_replace(['work_assignment_id' => $this->assignment->id, 'replacement_employee_id' => $employee->id, 'start_date' => '2026-10-05', 'end_date' => '2026-10-10'], $over);
    }

    private function save(Employee $employee, array $over = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->postJson('/api/v1/admin/hr/temporary-replacements', $this->body($employee, $over));
    }

    private function statusOf(Employee $employee): string
    {
        return (string) DB::table('employees')->where('id', $employee->id)->value('status');
    }

    /** @return array<string, array{string}> */
    public static function preActive(): array
    {
        return ['Waiting' => ['waiting'], 'Applicant' => ['applicant']];
    }

    // ------------------------------------------------------------------ Active is unchanged

    public function test_an_active_temporary_employee_is_saved_and_stays_active_with_no_status_change_recorded(): void
    {
        $person = $this->person('active');

        $this->save($person)->assertCreated()->assertJsonPath('data.replacement_employee_id', $person->id);

        $this->assertSame('active', $this->statusOf($person));
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $person->id)->count(), 'nothing is written to the status history for a person who was already Active');
    }

    // ------------------------------------------------------------------ choosing is not saving

    #[DataProvider('preActive')]
    public function test_choosing_a_waiting_or_applicant_person_changes_nothing_until_the_cover_is_saved(string $status): void
    {
        $person = $this->person($status);

        // What the form does while a person is picked: the live conflict check (and nothing else).
        $this->withToken($this->token)->getJson('/api/v1/admin/hr/temporary-replacements/conflict-check?'.http_build_query($this->body($person)))->assertOk()->assertJsonPath('data.conflict', null);
        $this->withToken($this->token)->getJson('/api/v1/admin/hr/employees?search='.urlencode($person->full_name).'&search_by_id=1')->assertOk();

        $this->assertSame($status, $this->statusOf($person));
        $this->assertSame(0, TemporaryReplacement::query()->count());
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $person->id)->count());
    }

    // ------------------------------------------------------------------ saving activates exactly that person

    public function test_saving_a_cover_for_a_waiting_person_activates_that_person_in_the_normal_way_and_only_that_person(): void
    {
        $person = $this->person('waiting');
        $bystander = $this->person('waiting');
        $applicant = $this->person('applicant');

        $this->save($person)->assertCreated();

        $this->assertSame('active', $this->statusOf($person));
        $this->assertSame(['waiting', 'applicant'], [$this->statusOf($bystander), $this->statusOf($applicant)], 'nobody else is activated');
        $history = EmployeeStatusHistory::query()->where('employee_id', $person->id)->sole();
        $this->assertSame(['waiting', 'active', 'status_update'], [$history->from_status->value, $history->to_status->value, $history->source], 'Waiting -> Active is a normal step of the lifecycle');
        $this->assertNull($history->override_reason);
        $this->assertSame(1, AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $person->id)->where('description', 'like', '%status changed from waiting to active%')->count());
        $this->assertSame(1, TemporaryReplacement::query()->where('replacement_employee_id', $person->id)->count());
    }

    public function test_saving_a_cover_for_an_applicant_activates_that_person_as_a_recorded_exception(): void
    {
        $person = $this->person('applicant');

        $this->save($person)->assertCreated();

        $this->assertSame('active', $this->statusOf($person));
        $history = EmployeeStatusHistory::query()->where('employee_id', $person->id)->sole();
        // Applicant -> Active is not a step of the lifecycle: it is made, but RECORDED (never silently) so HR can follow it up.
        $this->assertSame(['applicant', 'active', 'temporary_cover_exception'], [$history->from_status->value, $history->to_status->value, $history->source]);
        $this->assertStringContainsString('temporary', strtolower((string) $history->override_reason));
        $this->assertSame(1, AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $person->id)->where('metadata->event', 'temporary_cover_exception')->count());
    }

    public function test_a_new_registration_that_has_not_finished_the_registration_steps_is_activated_as_a_recorded_exception_too(): void
    {
        $person = $this->person('waiting', origin: 'new');

        $this->save($person)->assertCreated();

        $this->assertSame('active', $this->statusOf($person));
        $history = EmployeeStatusHistory::query()->where('employee_id', $person->id)->sole();
        $this->assertSame('temporary_cover_exception', $history->source);
        $this->assertStringContainsString('Guarantor verified', (string) $history->override_reason, 'what was still missing is written down');
    }

    // ------------------------------------------------------------------ failure leaves nothing behind

    #[DataProvider('preActive')]
    public function test_a_refused_cover_leaves_the_person_exactly_as_they_were(string $status): void
    {
        $person = $this->person($status);
        $first = $this->person('active');
        $this->save($first)->assertCreated(); // the position is covered 5-10 October

        // Overlapping the same position: refused by the existing overlap rule.
        $this->save($person, ['start_date' => '2026-10-08', 'end_date' => '2026-10-12'])->assertStatus(422);

        $this->assertSame($status, $this->statusOf($person));
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $person->id)->count());
        $this->assertSame(0, TemporaryReplacement::query()->where('replacement_employee_id', $person->id)->count());
    }

    #[DataProvider('preActive')]
    public function test_a_request_that_fails_validation_leaves_the_person_exactly_as_they_were(string $status): void
    {
        $person = $this->person($status);

        $this->save($person, ['start_date' => null])->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');
        $this->save($person, ['end_date' => '2026-10-01'])->assertStatus(422); // before the start

        $this->assertSame($status, $this->statusOf($person));
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $person->id)->count());
    }

    #[DataProvider('preActive')]
    public function test_a_database_failure_after_the_activation_rolls_the_activation_back_with_the_cover(string $status): void
    {
        $person = $this->person($status);

        // The cover row is the last thing saved: make that very write fail.
        TemporaryReplacement::creating(function (): never {
            throw new \RuntimeException('simulated database failure');
        });

        try {
            $this->save($person);
        } catch (\Throwable) {
            // the exception reaches the test as a 500 or is rethrown, either way nothing may remain
        }

        TemporaryReplacement::flushEventListeners();
        $this->assertSame($status, $this->statusOf($person), 'the activation is part of the same transaction');
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $person->id)->count());
        $this->assertSame(0, AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $person->id)->count());
        $this->assertSame(0, TemporaryReplacement::query()->count());
    }

    public function test_saving_the_same_cover_twice_cannot_create_a_second_cover_or_a_second_status_change(): void
    {
        $person = $this->person('waiting');

        $this->save($person)->assertCreated();
        $this->save($person)->assertStatus(422); // the second submission overlaps the first

        $this->assertSame(1, TemporaryReplacement::query()->where('replacement_employee_id', $person->id)->count());
        $this->assertSame(1, EmployeeStatusHistory::query()->where('employee_id', $person->id)->count());
        $this->assertSame('active', $this->statusOf($person));
    }

    // ------------------------------------------------------------------ nothing else about who may cover changed

    #[DataProvider('otherStatuses')]
    public function test_every_other_status_is_still_refused_and_unchanged(string $status): void
    {
        $person = $this->person($status);

        $this->save($person)->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');

        $this->assertSame($status, $this->statusOf($person));
    }

    /** @return array<string, array{string}> */
    public static function otherStatuses(): array
    {
        return ['Recruitment' => ['recruitment'], 'Practical' => ['practical'], 'Approved' => ['approved'], 'Inactive' => ['inactive']];
    }

    public function test_an_active_permanent_employee_is_still_refused_and_never_converted(): void
    {
        $person = $this->person('active', type: 'permanent');

        $this->save($person)->assertStatus(422)->assertJsonPath('error_code', 'REPLACEMENT_EMPLOYEE_NOT_TEMPORARY');

        $this->assertSame(['active', 'permanent'], [$this->statusOf($person), $this->typeOf($person)]);
        $this->assertSame(0, TemporaryReplacement::query()->count());
    }

    // ------------------------------------------------------------------ the type: a Waiting / Applicant person becomes Temporary when the cover is saved

    private function typeOf(Employee $employee): string
    {
        return (string) DB::table('employees')->where('id', $employee->id)->value('employment_type');
    }

    #[DataProvider('preActive')]
    public function test_a_waiting_or_applicant_person_with_no_real_type_becomes_active_and_temporary_only_when_the_cover_is_saved(string $status): void
    {
        // The stored value of a person who was never given a type is the column default, 'permanent'.
        $person = $this->person($status, type: 'permanent');
        $bystander = $this->person($status, type: 'permanent');
        $activePermanent = $this->person('active', type: 'permanent');

        $this->assertSame([$status, 'permanent'], [$this->statusOf($person), $this->typeOf($person)], 'choosing / looking changes nothing');

        $this->save($person)->assertCreated();

        $this->assertSame(['active', 'temporary'], [$this->statusOf($person), $this->typeOf($person)]);
        $this->assertSame([$status, 'permanent'], [$this->statusOf($bystander), $this->typeOf($bystander)], 'nobody else is touched');
        $this->assertSame(['active', 'permanent'], [$this->statusOf($activePermanent), $this->typeOf($activePermanent)], 'unrelated employees keep their type');
        $this->assertSame(1, AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $person->id)->where('metadata->event', 'temporary_cover_type_set')->count());
        $this->assertSame(1, TemporaryReplacement::query()->where('replacement_employee_id', $person->id)->count());
    }

    public function test_an_applicant_made_temporary_and_active_is_listed_by_the_compliance_trail_as_a_recorded_exception(): void
    {
        $person = $this->person('applicant', type: 'permanent');

        $this->save($person)->assertCreated();

        $history = EmployeeStatusHistory::query()->where('employee_id', $person->id)->sole();
        $this->assertSame(['applicant', 'active', 'temporary_cover_exception'], [$history->from_status->value, $history->to_status->value, $history->source]);
        $this->assertSame(1, AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $person->id)->where('metadata->event', 'temporary_cover_exception')->count());
    }

    public function test_the_hr_compliance_report_lists_an_applicant_made_active_for_a_cover_but_not_a_waiting_person_or_an_active_one(): void
    {
        $applicant = $this->person('applicant', type: 'permanent');
        $waiting = $this->person('waiting', type: 'permanent');
        $active = $this->person('active');
        $this->save($applicant)->assertCreated();
        $this->save($waiting, ['start_date' => '2026-10-12', 'end_date' => '2026-10-14'])->assertCreated();
        $this->save($active, ['start_date' => '2026-10-16', 'end_date' => '2026-10-18'])->assertCreated();

        $this->app['auth']->forgetGuards();
        // Owner decision UG-1: reading the report never scans; the explicit scan runs first, as the "Run scan" button does.
        $this->withToken($this->token)->postJson('/api/v1/admin/hr/compliance/scan')->assertOk();
        $this->app['auth']->forgetGuards();
        $findings = $this->withToken($this->token)->getJson('/api/v1/admin/hr/compliance/findings')->assertOk()->json('data.findings');
        $codesOf = fn (Employee $e): array => array_column(array_filter($findings, fn (array $f): bool => $f['employee_id'] === $e->id), 'code');

        $this->assertContains('APPLICANT_ACTIVATED_FOR_TEMPORARY_COVER', $codesOf($applicant));
        $this->assertNotContains('APPLICANT_ACTIVATED_FOR_TEMPORARY_COVER', $codesOf($waiting), 'Waiting -> Active is a normal step');
        $this->assertNotContains('APPLICANT_ACTIVATED_FOR_TEMPORARY_COVER', $codesOf($active));
        $row = collect($findings)->firstWhere('code', 'APPLICANT_ACTIVATED_FOR_TEMPORARY_COVER');
        $this->assertSame('needs_review', $row['confidence']);
        $this->assertStringContainsString('temporary replacement', strtolower((string) $row['explanation']));
    }

    #[DataProvider('preActive')]
    public function test_a_refused_cover_leaves_status_and_type_unchanged(string $status): void
    {
        $person = $this->person($status, type: 'permanent');
        $this->save($this->person('active'))->assertCreated();

        $this->save($person, ['start_date' => '2026-10-08', 'end_date' => '2026-10-12'])->assertStatus(422); // overlaps the first cover

        $this->assertSame([$status, 'permanent'], [$this->statusOf($person), $this->typeOf($person)]);
        $this->assertSame(0, AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $person->id)->count());
    }

    #[DataProvider('preActive')]
    public function test_a_database_failure_rolls_the_type_change_back_too(string $status): void
    {
        $person = $this->person($status, type: 'permanent');
        TemporaryReplacement::creating(function (): never {
            throw new \RuntimeException('simulated database failure');
        });

        try {
            $this->save($person);
        } catch (\Throwable) {
            // either a 500 or rethrown: nothing may remain
        }

        TemporaryReplacement::flushEventListeners();
        $this->assertSame([$status, 'permanent'], [$this->statusOf($person), $this->typeOf($person)]);
        $this->assertSame(0, AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $person->id)->count());
    }

    #[DataProvider('preActive')]
    public function test_a_person_who_still_holds_an_active_assignment_is_not_converted_and_the_save_is_refused(string $status): void
    {
        $person = $this->person($status, type: 'permanent');
        // An Active assignment that starts later: it does not overlap the cover (so the overlap rule is not what refuses), but it would lose its pay line.
        $this->makeAssignment($person, $this->makeCompany('Other Co'), null, 200, '2026-12-01');

        $this->save($person)->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYMENT_TYPE_CHANGE_BLOCKED_ACTIVE_ASSIGNMENT');

        $this->assertSame([$status, 'permanent'], [$this->statusOf($person), $this->typeOf($person)]);
        $this->assertSame(0, TemporaryReplacement::query()->count());
    }

    public function test_an_active_temporary_employee_keeps_their_type_and_gets_no_type_audit(): void
    {
        $person = $this->person('active');

        $this->save($person)->assertCreated();

        $this->assertSame(['active', 'temporary'], [$this->statusOf($person), $this->typeOf($person)]);
        $this->assertSame(0, AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $person->id)->count());
    }

    public function test_the_new_cover_pool_lists_waiting_and_applicant_people_of_any_type_but_only_temporary_active_people(): void
    {
        $activeTemp = $this->person('active');
        $activePerm = $this->person('active', type: 'permanent');
        $waitingPerm = $this->person('waiting', type: 'permanent');
        $applicantPerm = $this->person('applicant', type: 'permanent');
        $approved = $this->person('approved');
        $all = [$activeTemp->id, $activePerm->id, $waitingPerm->id, $applicantPerm->id, $approved->id];

        $ids = collect($this->withToken($this->token)->getJson('/api/v1/admin/hr/employees?statuses=active,waiting,applicant&employment_type=temporary&cover_pool=1&per_page=100')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$activeTemp->id, $waitingPerm->id, $applicantPerm->id], array_values(array_intersect($ids, $all)));
    }

    public function test_a_person_cannot_cover_their_own_position_and_is_not_activated_by_trying(): void
    {
        $covered = $this->assignment->employee;
        $covered->update(['status' => 'waiting', 'employment_type' => 'temporary']);

        $this->save($covered)->assertStatus(422);

        $this->assertSame('waiting', $this->statusOf($covered));
    }

    // ------------------------------------------------------------------ the picker's list

    public function test_the_employee_list_can_be_filtered_by_several_statuses_at_once(): void
    {
        $active = $this->person('active');
        $waiting = $this->person('waiting');
        $applicant = $this->person('applicant');
        $approved = $this->person('approved');
        $permanentWaiting = $this->person('waiting', type: 'permanent');

        $ids = collect($this->withToken($this->token)->getJson('/api/v1/admin/hr/employees?statuses=active,waiting,applicant&employment_type=temporary&per_page=50')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing(
            [$active->id, $waiting->id, $applicant->id],
            array_values(array_intersect($ids, [$active->id, $waiting->id, $applicant->id, $approved->id, $permanentWaiting->id])),
        );
        $this->withToken($this->token)->getJson('/api/v1/admin/hr/employees?statuses=active,bogus')->assertStatus(422);
        $this->withToken($this->token)->getJson('/api/v1/admin/hr/employees?status=active&employment_type=temporary')->assertOk(); // the single-status filter is unchanged
    }
}
