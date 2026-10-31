<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The two pickers of the "New Temporary Replacement" form (the Permanent employee being covered, the Temporary employee doing the cover) used to
 * ask for the first 100 Active people and filter their type in the browser: production has 299 Active people, so most were never offered, and a
 * Temporary beyond the first 100 was invisible. The server now filters by `employment_type`, pages without gaps and, when asked (`search_by_id`),
 * also finds a person by the database ID.
 *
 * Nothing about WHO MAY COVER changed: the cover is still refused for anyone who is not Active, not Temporary, not fully registered, or is the
 * covered person - the last test pins those rules, the picker only decides who is shown.
 */
class EmployeePickerFiltersTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view']);
    }

    private function list(string $query): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->getJson('/api/v1/admin/hr/employees?'.$query)->assertOk();
    }

    private function person(string $status, string $type, string $name, string $date = '2026-10-01'): Employee
    {
        $e = $this->makeEmployee($status, $type);
        $e->update(['full_name' => $name, 'application_date' => $date, 'registration_origin' => 'existing']);

        return $e;
    }

    public function test_the_employment_type_filter_is_applied_on_the_server_and_pages_add_up(): void
    {
        $temporaries = collect(range(1, 5))->map(fn ($i) => $this->person('active', 'temporary', "Temp Person {$i}"))->pluck('id')->all();
        foreach (range(1, 6) as $i) {
            $this->person('active', 'permanent', "Perm Person {$i}");
        }
        $this->person('inactive', 'temporary', 'Temp Inactive');
        $this->person('waiting', 'temporary', 'Temp Waiting');

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->list("status=active&employment_type=temporary&per_page=2&page={$page}");
            $this->assertSame(5, $response->json('meta.total'), 'only the Active Temporary people are counted');
            $this->assertSame(3, $response->json('meta.last_page'));
            foreach ($response->json('data') as $row) {
                $this->assertSame('temporary', $row['employment_type']);
                $this->assertSame('active', $row['status']);
                $seen[] = $row['id'];
            }
        }
        $this->assertEqualsCanonicalizing($temporaries, $seen);

        $permanent = $this->list('status=active&employment_type=permanent&per_page=100');
        $this->assertSame(6, $permanent->json('meta.total'));
        $this->assertSame(['permanent'], collect($permanent->json('data'))->pluck('employment_type')->unique()->values()->all());

        $this->assertSame(11, $this->list('status=active&per_page=100')->json('meta.total'), 'without the filter the list is what it always was');
    }

    public function test_a_temporary_beyond_the_first_hundred_people_is_found_with_the_filter(): void
    {
        // The case behind the defect: more than 100 newer Permanent people hide an older Temporary on the first page of 100.
        foreach (range(1, 105) as $i) {
            $this->person('active', 'permanent', "Permanent {$i}", '2026-10-20');
        }
        $old = $this->person('active', 'temporary', 'Old Temporary', '2026-01-05');

        $firstPage = $this->list('status=active&per_page=100');
        $this->assertSame(106, $firstPage->json('meta.total'));
        $this->assertNotContains($old->id, collect($firstPage->json('data'))->pluck('id')->all(), 'the old behaviour: the Temporary is not on the first 100');

        $filtered = $this->list('status=active&employment_type=temporary&per_page=100');
        $this->assertSame([$old->id], collect($filtered->json('data'))->pluck('id')->all());
    }

    public function test_each_listed_person_says_whether_the_registration_workflow_is_complete_so_the_picker_can_show_who_will_be_refused(): void
    {
        $ready = $this->person('active', 'temporary', 'Ready Temporary');
        $notReady = $this->person('active', 'temporary', 'Not Ready Temporary');
        $notReady->update(['registration_origin' => 'new']);

        $rows = collect($this->list('status=active&employment_type=temporary&per_page=100')->json('data'))->keyBy('id');

        $this->assertTrue($rows[$ready->id]['registration_workflow']['eligible_for_waiting_and_company']);
        $this->assertFalse($rows[$notReady->id]['registration_workflow']['eligible_for_waiting_and_company']);
        $this->assertContains('Guarantor verified', $rows[$notReady->id]['registration_workflow']['missing']);
    }

    public function test_paging_never_repeats_or_skips_people_that_share_an_application_date(): void
    {
        foreach (range(1, 23) as $i) {
            $this->person('active', 'permanent', "Same Day {$i}", '2026-10-10');
        }

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $seen = [...$seen, ...collect($this->list("status=active&employment_type=permanent&per_page=10&page={$page}")->json('data'))->pluck('id')->all()];
        }

        $this->assertCount(23, $seen);
        $this->assertSame($seen, array_values(array_unique($seen)), 'no one appears twice');
    }

    public function test_search_finds_a_person_by_name_employee_number_and_database_id(): void
    {
        $target = $this->person('active', 'temporary', 'Hodan Qasim Target');
        $target->update(['employee_number' => 'EMP-424242']);
        $this->person('active', 'temporary', 'Somebody Else');
        $permanentWithId = $this->person('active', 'permanent', 'Permanent Person');

        $ids = fn (string $q) => collect($this->list($q)->json('data'))->pluck('id')->all();

        $this->assertSame([$target->id], $ids('status=active&employment_type=temporary&search=qasim+target'), 'by name');
        $this->assertSame([$target->id], $ids('status=active&employment_type=temporary&search=EMP-424242'), 'by employee number');
        $this->assertSame([$target->id], $ids('status=active&employment_type=temporary&search=424242'), 'by part of the employee number');
        // A typed number also matches phones and numbers that merely contain it (as every search does), so the exact ID is put first.
        $byId = $ids("status=active&employment_type=temporary&search_by_id=1&search={$target->id}");
        $this->assertSame($target->id, $byId[0], 'by database ID, listed first');

        // The ID match is opt-in: the other lists search name, phone and number only, exactly as before.
        $other = $this->person('active', 'temporary', 'Id Three Hundred');
        $other->update(['phone' => '0000000000', 'employee_number' => 'EMP-000000']);
        $this->assertNotContains($other->id, $ids("status=active&employment_type=temporary&search={$other->id}"));
        $this->assertSame($other->id, $ids("status=active&employment_type=temporary&search_by_id=1&search={$other->id}")[0], 'only the ID can match this person, and it is listed first');

        // The exact employee number comes first too.
        $this->assertSame($target->id, $ids('status=active&employment_type=temporary&search_by_id=1&search=emp-424242')[0]);

        // An ID never overrides the other filters: a Permanent person's ID finds nobody among Temporary people.
        $this->assertNotContains($permanentWithId->id, $ids("status=active&employment_type=temporary&search_by_id=1&search={$permanentWithId->id}"));
        // ... and a non-number is never treated as an ID.
        $this->assertSame([], $ids('status=active&employment_type=temporary&search_by_id=1&search=nobody-here'));
    }

    public function test_the_new_filter_values_are_validated_and_the_list_still_needs_hr_view(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/v1/admin/hr/employees?employment_type=casual')->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');
        $this->withToken($this->token)->getJson('/api/v1/admin/hr/employees?per_page=101')->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenWithOnly([]))->getJson('/api/v1/admin/hr/employees?employment_type=temporary')->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson('/api/v1/admin/hr/employees?employment_type=temporary')->assertStatus(401);
    }

    public function test_who_may_cover_is_unchanged_the_picker_only_decides_who_is_shown(): void
    {
        $super = $this->tokenForRoleWith(AdminRole::SuperAdmin, []);
        $company = $this->makeCompany();
        $location = $this->makeClientLocation($company);
        $covered = $this->person('active', 'permanent', 'Covered Permanent');
        $assignment = $this->makeAssignment($covered, $company, $location);

        $cover = function (Employee $who) use ($super, $assignment): TestResponse {
            $this->app['auth']->forgetGuards();

            return $this->withToken($super)->postJson('/api/v1/admin/hr/temporary-replacements', [
                'work_assignment_id' => $assignment->id,
                'replacement_employee_id' => $who->id,
                'start_date' => '2026-10-20',
            ]);
        };

        // Waiting List people stay ineligible: only Active people may cover (an owner decision, not part of this change).
        $cover($this->person('waiting', 'temporary', 'Waiting Temporary'))->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');
        // Active but not classified Temporary.
        $cover($this->person('active', 'permanent', 'Active Permanent'))->assertStatus(422)->assertJsonPath('error_code', 'REPLACEMENT_EMPLOYEE_NOT_TEMPORARY');
        // A new registration that has not completed the registration workflow.
        $incomplete = $this->person('active', 'temporary', 'Incomplete Temporary');
        $incomplete->update(['registration_origin' => 'new']);
        $cover($incomplete)->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_REGISTRATION_INCOMPLETE');
        // The covered person cannot cover their own position.
        $cover($covered)->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_CANNOT_REPLACE_SELF');

        // And the picker's filter is not a way around any of it: it only lists, it never creates or converts anyone.
        $before = Employee::query()->pluck('employment_type', 'id')->all();
        $this->list('status=active&employment_type=temporary&per_page=100');
        $this->assertSame($before, Employee::query()->pluck('employment_type', 'id')->all(), 'no one is converted by listing');
    }
}
