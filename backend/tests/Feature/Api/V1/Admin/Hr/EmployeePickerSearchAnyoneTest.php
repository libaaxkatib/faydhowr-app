<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Employee;
use App\Models\TemporaryReplacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * SEARCHING an employee and BEING ABLE TO COVER are different questions. The pickers of the New / Correct Temporary replacement forms list the people who can
 * be chosen when nothing is typed, and search EVERY employee (name, employee number, phone, database ID; no status and no type filter) as soon as something
 * is typed, so the person being looked for is found; the screen greys out whoever cannot be chosen.
 *
 * Finding a person never makes them eligible: the server still refuses, on save, every person who is not an Active, registered Temporary employee, and it
 * never changes anyone's type. These tests pin both halves.
 */
class EmployeePickerSearchAnyoneTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $viewer;

    private string $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view']);
        $this->super = $this->tokenForRoleWith(AdminRole::SuperAdmin, []);
    }

    /** The exact query of a typed search, as the picker sends it (page 1 unless told). */
    private function search(string $term, int $page = 1): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->viewer)->getJson('/api/v1/admin/hr/employees?'.http_build_query(['search' => $term, 'search_by_id' => 'true', 'per_page' => 50, 'page' => $page]))->assertOk();
    }

    private function person(string $status, string $type, string $name, string $date = '2026-10-01'): Employee
    {
        $e = $this->makeEmployee($status, $type);
        $e->update(['full_name' => $name, 'application_date' => $date, 'registration_origin' => 'existing']);

        return $e;
    }

    public function test_a_search_finds_the_person_by_name_by_employee_number_and_by_database_id_whatever_their_type_or_status(): void
    {
        $permanent = $this->person('active', 'permanent', 'Amina Findme Permanent');
        $permanent->update(['employee_number' => 'EMP-777001']);
        $waiting = $this->person('waiting', 'temporary', 'Bashir Findme Waiting');
        $inactive = $this->person('inactive', 'permanent', 'Cabdi Findme Inactive');

        $ids = fn (string $term) => collect($this->search($term)->json('data'))->pluck('id')->all();

        $this->assertSame([$permanent->id], $ids('Amina Findme'), 'a Permanent person is found by name');
        $this->assertSame([$permanent->id], $ids('emp-777001'), 'by employee number, any case');
        $this->assertSame($permanent->id, $ids((string) $permanent->id)[0], 'by database ID, listed first');
        $this->assertSame([$waiting->id], $ids('Bashir Findme'), 'a Waiting List person is found');
        $this->assertSame([$inactive->id], $ids('Cabdi Findme'), 'an Inactive person is found');
    }

    public function test_a_person_beyond_the_first_fifty_or_hundred_people_is_found_by_a_typed_search(): void
    {
        // 130 newer people hide an old Permanent person behind the first two pages of the list.
        foreach (range(1, 130) as $i) {
            $this->person('active', 'permanent', "Newer Person {$i}", '2026-10-20');
        }
        $old = $this->person('active', 'permanent', 'Zainab Oldest Hidden', '2025-01-02');
        $old->update(['employee_number' => 'EMP-000042']);

        $list = collect($this->search('Newer Person')->json('data'))->pluck('id')->all();
        $this->assertNotContains($old->id, $list, 'she is not among the first page of unrelated people');

        $this->assertSame([$old->id], collect($this->search('Zainab Oldest')->json('data'))->pluck('id')->all(), 'by name');
        $this->assertSame([$old->id], collect($this->search('EMP-000042')->json('data'))->pluck('id')->all(), 'by employee number');
        $this->assertSame($old->id, $this->search((string) $old->id)->json('data.0.id'), 'by database ID');
    }

    public function test_search_results_page_without_gaps_or_repeats_and_a_different_search_starts_again_from_its_first_page(): void
    {
        foreach (range(1, 120) as $i) {
            $this->person($i % 3 === 0 ? 'waiting' : 'active', $i % 2 === 0 ? 'temporary' : 'permanent', sprintf('Pagetest %03d', $i), '2026-10-10');
        }
        $this->person('active', 'permanent', 'Different Word', '2026-10-10');

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $r = $this->search('Pagetest', $page);
            $this->assertSame(120, $r->json('meta.total'), "page {$page}: every status and type is in the total");
            $this->assertSame(3, $r->json('meta.last_page'));
            $this->assertCount($page < 3 ? 50 : 20, $r->json('data'));
            $seen = [...$seen, ...collect($r->json('data'))->pluck('id')->all()];
        }
        $this->assertCount(120, array_unique($seen), '"Show more" never repeats or skips anyone');

        $other = $this->search('Different');
        $this->assertSame(1, $other->json('meta.total'));
        $this->assertSame(1, $other->json('meta.current_page'), 'a new search is page 1');
    }

    public function test_archived_employees_are_not_found_and_the_list_with_nothing_typed_is_still_the_pool_of_eligible_people(): void
    {
        $archived = $this->person('active', 'temporary', 'Archived Findme');
        $archived->delete();
        $this->person('active', 'temporary', 'Pool Temporary One');
        $this->person('active', 'permanent', 'Pool Permanent');
        $this->person('waiting', 'temporary', 'Pool Waiting Temporary');

        $this->assertSame([], $this->search('Archived Findme')->json('data'), 'removed people stay out of every list');

        $this->app['auth']->forgetGuards();
        $pool = $this->withToken($this->viewer)->getJson('/api/v1/admin/hr/employees?status=active&employment_type=temporary&per_page=50&page=1')->assertOk();
        $this->assertSame(['Pool Temporary One'], collect($pool->json('data'))->pluck('full_name')->all(), 'nothing typed: only Active Temporary people');
    }

    public function test_finding_a_person_never_makes_them_eligible_the_server_still_refuses_and_changes_nobody(): void
    {
        $company = $this->makeCompany();
        $location = $this->makeClientLocation($company);
        $covered = $this->person('active', 'permanent', 'Covered Holder');
        $assignment = $this->makeAssignment($covered, $company, $location);

        $permanent = $this->person('active', 'permanent', 'Eligibility Permanent');
        $waiting = $this->person('waiting', 'temporary', 'Eligibility Waiting');
        $inactive = $this->person('inactive', 'temporary', 'Eligibility Inactive');
        $incomplete = $this->person('active', 'temporary', 'Eligibility Incomplete');
        $incomplete->update(['registration_origin' => 'new']);
        $typesBefore = Employee::query()->pluck('employment_type', 'id')->all();
        $statusesBefore = Employee::query()->pluck('status', 'id')->map(fn ($s) => $s instanceof \BackedEnum ? $s->value : $s)->all();

        $cover = function (Employee $who) use ($assignment): TestResponse {
            $this->app['auth']->forgetGuards();

            return $this->withToken($this->super)->postJson('/api/v1/admin/hr/temporary-replacements', ['work_assignment_id' => $assignment->id, 'replacement_employee_id' => $who->id, 'start_date' => '2026-10-20']);
        };

        // Each of them IS found by the search ...
        foreach (['Eligibility Permanent' => $permanent, 'Eligibility Waiting' => $waiting, 'Eligibility Inactive' => $inactive, 'Eligibility Incomplete' => $incomplete] as $term => $who) {
            $this->assertSame([$who->id], collect($this->search($term)->json('data'))->pluck('id')->all(), "{$term} is found");
        }
        // ... and each is still refused when someone tries to use them.
        $cover($permanent)->assertStatus(422)->assertJsonPath('error_code', 'REPLACEMENT_EMPLOYEE_NOT_TEMPORARY');
        $cover($waiting)->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');
        $cover($inactive)->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');
        $cover($incomplete)->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_REGISTRATION_INCOMPLETE');
        $cover($covered)->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_CANNOT_REPLACE_SELF');

        $this->assertSame(0, TemporaryReplacement::query()->count(), 'no cover was created');
        $this->assertSame($typesBefore, Employee::query()->pluck('employment_type', 'id')->all(), 'nobody was converted to Temporary (or back)');
        $this->assertSame($statusesBefore, Employee::query()->pluck('status', 'id')->map(fn ($s) => $s instanceof \BackedEnum ? $s->value : $s)->all(), 'nobody changed status');
    }

    public function test_an_eligible_temporary_employee_found_by_the_search_can_cover(): void
    {
        $company = $this->makeCompany();
        $assignment = $this->makeAssignment($this->person('active', 'permanent', 'Covered Holder'), $company, $this->makeClientLocation($company));
        $temp = $this->person('active', 'temporary', 'Hodan Cover Candidate');

        $found = $this->search('Hodan Cover')->json('data.0');
        $this->assertSame($temp->id, $found['id']);
        $this->assertTrue($found['registration_workflow']['eligible_for_waiting_and_company'], 'the row says the registration is complete');
        $this->assertSame('temporary', $found['employment_type']);
        $this->assertSame('active', $found['status']);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->super)->postJson('/api/v1/admin/hr/temporary-replacements', ['work_assignment_id' => $assignment->id, 'replacement_employee_id' => $found['id'], 'start_date' => '2026-10-20'])->assertCreated();
        $this->assertSame(1, TemporaryReplacement::query()->count());
    }

    public function test_the_search_still_needs_hr_view_and_validates_its_input(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenWithOnly([]))->getJson('/api/v1/admin/hr/employees?search=a&search_by_id=true')->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->viewer)->getJson('/api/v1/admin/hr/employees?search='.str_repeat('a', 151))->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson('/api/v1/admin/hr/employees?search=a')->assertStatus(401);
    }
}
