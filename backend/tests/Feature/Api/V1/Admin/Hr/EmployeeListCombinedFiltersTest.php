<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Department;
use App\Models\EmployeeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * BUG-06: the filters of the Employees list work TOGETHER and with pagination - every page of a combined filter holds only matching people,
 * the pages add up to the total, nothing repeats, and a filter that matches nobody says so.
 */
class EmployeeListCombinedFiltersTest extends TestCase
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

    public function test_status_category_department_and_search_combine_and_every_page_holds_only_matching_people(): void
    {
        $a = EmployeeCategory::query()->create(['name' => 'Category A']);
        $b = EmployeeCategory::query()->create(['name' => 'Category B']);
        $dept = Department::query()->create(['name' => 'Night']);

        $expected = [];
        foreach (range(1, 5) as $i) {
            $e = $this->makeEmployee('waiting');
            $e->update(['full_name' => "Amina Match {$i}", 'employee_category_id' => $a->id, 'department_id' => $dept->id]);
            $expected[] = $e->id;
        }
        // Near misses: each differs from the target in exactly one filter.
        $this->makeEmployee('active')->update(['full_name' => 'Amina Wrong Status', 'employee_category_id' => $a->id, 'department_id' => $dept->id]);
        $this->makeEmployee('waiting')->update(['full_name' => 'Amina Wrong Category', 'employee_category_id' => $b->id, 'department_id' => $dept->id]);
        $this->makeEmployee('waiting')->update(['full_name' => 'Amina Wrong Department', 'employee_category_id' => $a->id, 'department_id' => null]);
        $this->makeEmployee('waiting')->update(['full_name' => 'Someone Else', 'employee_category_id' => $a->id, 'department_id' => $dept->id]);

        $base = "status=waiting&employee_category_id={$a->id}&department_id={$dept->id}&search=Amina+Match&per_page=2";
        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->list("{$base}&page={$page}");
            $this->assertSame(5, $response->json('meta.total'), "page {$page} reports the same total");
            $this->assertSame(3, $response->json('meta.last_page'));
            $ids = collect($response->json('data'))->pluck('id')->all();
            $this->assertCount($page < 3 ? 2 : 1, $ids);
            $seen = [...$seen, ...$ids];
        }

        $this->assertEqualsCanonicalizing($expected, $seen, 'the pages add up to exactly the matching people');
        $this->assertSame($seen, array_values(array_unique($seen)), 'no person is repeated across pages');
    }

    public function test_a_filter_combination_that_matches_nobody_says_so_and_a_page_past_the_end_is_empty(): void
    {
        $this->makeEmployee('waiting');

        $none = $this->list('status=active&search=Nobody+Here&per_page=2');
        $this->assertSame(0, $none->json('meta.total'));
        $this->assertSame([], $none->json('data'));

        $past = $this->list('status=waiting&per_page=2&page=9');
        $this->assertSame([], $past->json('data'));
        $this->assertSame(1, $past->json('meta.total'));
    }

    public function test_the_profile_and_supervisor_filters_combine_with_a_status(): void
    {
        $sup = $this->makeEmployee('active');
        $sup->forceFill(['is_supervisor' => true])->save();
        $this->makeEmployee('active');
        $this->makeEmployee('waiting')->forceFill(['is_supervisor' => true])->save();

        $r = $this->list('status=active&is_supervisor=true');
        $this->assertSame([$sup->id], collect($r->json('data'))->pluck('id')->all());
    }
}
