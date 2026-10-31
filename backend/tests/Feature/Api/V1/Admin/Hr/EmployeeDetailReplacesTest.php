<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The employee detail says WHO a Replacement took the position over from, using only the recorded link (`replaces_assignment_id`) and that assignment's
 * employee - the same relationship the company employee list shows. Nothing is inferred from a name, a position or a date, and no other response changes.
 */
class EmployeeDetailReplacesTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view']);
    }

    private function detail(Employee $employee): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->getJson("/api/v1/admin/hr/employees/{$employee->id}")->assertOk();
    }

    /** A Permanent employee who left (ended assignment) and the Replacement who took the position over. @return array{Employee, EmployeeWorkAssignment, Employee, EmployeeWorkAssignment} */
    private function chain(string $companyName = 'Chain Co'): array
    {
        $company = $this->makeCompany($companyName);
        $location = $this->makeClientLocation($company);

        $left = $this->makeEmployee('active');
        $leftAssignment = $this->makeAssignment($left, $company, $location, 300, '2026-06-01');
        $leftAssignment->forceFill(['status' => 'ended', 'end_date' => '2026-10-14'])->save();

        $successor = $this->makeEmployee('active');
        $successorAssignment = $this->makeAssignment($successor, $company, $location, 300, '2026-10-15');
        $successorAssignment->forceFill(['assignment_type' => 'replacement', 'replaces_assignment_id' => $leftAssignment->id])->save();

        return [$left, $leftAssignment, $successor, $successorAssignment];
    }

    public function test_a_linked_replacement_shows_the_employee_it_replaces_from_the_recorded_link(): void
    {
        [$left, $leftAssignment, $successor, $successorAssignment] = $this->chain();

        $row = collect($this->detail($successor)->json('data.active_work_assignments'))->firstWhere('id', $successorAssignment->id);

        $this->assertSame('replacement', $row['assignment_type']);
        $this->assertSame($leftAssignment->id, $row['replaces_assignment_id']);
        $this->assertSame(
            ['assignment_id' => $leftAssignment->id, 'employee_id' => $left->id, 'employee_number' => $left->employee_number, 'full_name' => $left->full_name],
            $row['replaces'],
        );
    }

    public function test_the_same_link_is_in_the_full_assignment_history(): void
    {
        [$left, $leftAssignment, $successor, $successorAssignment] = $this->chain();

        $history = collect($this->detail($successor)->json('data.work_assignments'));

        $this->assertSame($left->id, $history->firstWhere('id', $successorAssignment->id)['replaces']['employee_id']);
    }

    public function test_a_replacement_with_no_recorded_link_has_no_replaces_and_nothing_is_invented(): void
    {
        $company = $this->makeCompany('Orphan Co');
        $orphan = $this->makeEmployee('active');
        $assignment = $this->makeAssignment($orphan, $company, $this->makeClientLocation($company), 300, '2026-10-15');
        $assignment->forceFill(['assignment_type' => 'replacement', 'replaces_assignment_id' => null])->save();
        // Another person holds the same company: it must not be guessed as the replaced one.
        $this->makeAssignment($this->makeEmployee('active'), $company, null, 300, '2026-01-01');

        $row = collect($this->detail($orphan)->json('data.active_work_assignments'))->firstWhere('id', $assignment->id);

        $this->assertSame('replacement', $row['assignment_type']);
        $this->assertNull($row['replaces_assignment_id']);
        $this->assertArrayNotHasKey('replaces', $row);
    }

    public function test_a_normal_permanent_employee_has_no_replaces(): void
    {
        [$left] = $this->chain();
        $company = $this->makeCompany('Plain Co');
        $plain = $this->makeEmployee('active');
        $assignment = $this->makeAssignment($plain, $company, $this->makeClientLocation($company), 300, '2026-03-01');

        $row = collect($this->detail($plain)->json('data.active_work_assignments'))->firstWhere('id', $assignment->id);
        $this->assertSame('permanent', $row['assignment_type']);
        $this->assertNull($row['replaces_assignment_id']);
        $this->assertArrayNotHasKey('replaces', $row);

        // The person who left is Permanent too: being replaced does not make their own assignment a Replacement.
        $leftRow = collect($this->detail($left)->json('data.work_assignments'))->first();
        $this->assertSame('permanent', $leftRow['assignment_type']);
        $this->assertArrayNotHasKey('replaces', $leftRow);
    }

    public function test_an_archived_replaced_employee_keeps_the_link_but_exposes_no_name(): void
    {
        [$left, $leftAssignment, $successor, $successorAssignment] = $this->chain();
        $left->delete();

        $row = collect($this->detail($successor)->json('data.active_work_assignments'))->firstWhere('id', $successorAssignment->id);

        $this->assertSame($left->id, $row['replaces']['employee_id'], 'the recorded link is still shown');
        $this->assertNull($row['replaces']['full_name']);
        $this->assertNull($row['replaces']['employee_number']);
    }

    public function test_the_extra_data_is_loaded_in_a_fixed_number_of_queries_and_nothing_is_written(): void
    {
        [, , $one] = $this->chain('Chain One');
        [, , $many] = $this->chain('Chain Many');
        foreach (range(1, 4) as $i) {
            $company = $this->makeCompany("More {$i}");
            $location = $this->makeClientLocation($company);
            $left = $this->makeEmployee('active');
            $leftAssignment = $this->makeAssignment($left, $company, $location, 300, '2026-05-01');
            $leftAssignment->forceFill(['status' => 'ended', 'end_date' => '2026-06-30'])->save();
            $this->makeAssignment($many, $company, $location, 300, '2026-07-01')->forceFill(['assignment_type' => 'replacement', 'replaces_assignment_id' => $leftAssignment->id])->save();
        }

        $count = function (Employee $employee): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->detail($employee);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };
        $before = EmployeeWorkAssignment::query()->orderBy('id')->get()->toArray();

        $single = $count($one);
        $this->assertLessThanOrEqual($single + 2, $count($many), 'five replaced assignments cost (about) the same number of queries as one: no query per assignment (an N+1 would add four or more)');
        $this->assertSame($before, EmployeeWorkAssignment::query()->orderBy('id')->get()->toArray(), 'reading the detail changes no assignment');
    }

    public function test_other_assignment_responses_are_unchanged(): void
    {
        [, $leftAssignment, $successor] = $this->chain();
        $super = $this->tokenForRoleWith(AdminRole::SuperAdmin, []);
        $company = $this->makeCompany('Create Co');

        $this->app['auth']->forgetGuards();
        $created = $this->withToken($super)->postJson("/api/v1/admin/hr/employees/{$this->makeEmployee('active')->id}/work-assignments", [
            'client_company_id' => $company->id, 'start_date' => '2026-10-20', 'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertCreated();

        // The create response loads `replaces` without its employee: it must not start carrying the new summary.
        $this->assertArrayNotHasKey('replaces', $created->json('data'));
        $this->assertArrayHasKey('replaces_assignment_id', $created->json('data'));
    }

    public function test_the_detail_still_needs_hr_view(): void
    {
        [, , $successor] = $this->chain();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenWithOnly([]))->getJson("/api/v1/admin/hr/employees/{$successor->id}")->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson("/api/v1/admin/hr/employees/{$successor->id}")->assertStatus(401);
    }
}
