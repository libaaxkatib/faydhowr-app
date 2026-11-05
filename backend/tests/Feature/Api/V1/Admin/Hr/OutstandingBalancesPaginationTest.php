<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeePenalty;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\Payroll\DeductionBalanceList;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision (2026-11-05): the Outstanding balances list is paged on the server, its totals and count are the real ones for the whole filtered set, the
 * old silent cut at 2,000 rows is gone, a truncated scan is said so, and the company / kind / leaver filters and the permission check still work.
 * Synthetic data only.
 */
class OutstandingBalancesPaginationTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $super;

    private ClientCompany $alpha;

    private ClientCompany $bravo;

    private Employee $staying;

    private Employee $leaver;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
        [, $this->super] = $this->superAdminToken();
        $this->alpha = $this->makeCompany('Alpha Co');
        $this->bravo = $this->makeCompany('Bravo Co');
        $this->staying = $this->makeEmployee();
        $this->leaver = $this->makeEmployee();
        $this->makeAssignment($this->staying, $this->alpha, null, 300, '2026-01-01');
        $this->makeAssignment($this->leaver, $this->bravo, null, 300, '2026-01-01');
        EmployeeWorkAssignment::query()->where('employee_id', $this->leaver->id)->update(['end_date' => '2026-09-30', 'status' => 'ended']);
    }

    protected function tearDown(): void
    {
        DeductionBalanceList::$scanLimit = DeductionBalanceList::MAX_SCAN;
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Inserts $count penalties of 1.00 for one employee / company straight into the table (fast), registered in 2026-10. */
    private function penalties(int $count, Employee $employee, ClientCompany $company): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['employee_id' => $employee->id, 'client_company_id' => $company->id, 'penalty_date' => '2026-10-10', 'reason' => "Late #{$i}", 'deduction_amount' => 1, 'currency' => 'USD', 'payroll_period' => '2026-10', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('employee_penalties')->insert($chunk);
        }
    }

    private function advances(int $count, Employee $employee, ClientCompany $company): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = ['employee_id' => $employee->id, 'client_company_id' => $company->id, 'advance_date' => '2026-10-12', 'reason' => "Rent #{$i}", 'amount' => 2, 'currency' => 'USD', 'payroll_period' => '2026-10', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('employee_advances')->insert($chunk);
        }
    }

    private function balances(string $query = '', ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->super)->getJson('/api/v1/admin/hr/payroll/deduction-balances'.($query === '' ? '' : '?'.$query));
    }

    public function test_every_record_is_reachable_page_by_page_and_the_total_is_the_real_one(): void
    {
        $this->penalties(130, $this->staying, $this->alpha);

        $first = $this->balances('per_page=50')->assertOk();
        $first->assertJsonPath('meta.current_page', 1)->assertJsonPath('meta.per_page', 50)->assertJsonPath('meta.total', 130)->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.from', 1)->assertJsonPath('meta.to', 50)->assertJsonPath('meta.truncated', false)
            ->assertJsonCount(50, 'data.rows')->assertJsonPath('data.totals.count', 130)->assertJsonPath('data.totals.owed_cents', 13000);

        $ids = [];

        foreach ([1, 2, 3] as $page) {
            $response = $this->balances("per_page=50&page={$page}")->assertOk();
            $response->assertJsonPath('data.totals.count', 130, 'the totals never shrink to the page');
            array_push($ids, ...array_column($response->json('data.rows'), 'id'));
        }

        $this->assertCount(130, $ids);
        $this->assertCount(130, array_unique($ids), 'no record is repeated or skipped between pages');
        $this->balances('per_page=50&page=3')->assertJsonPath('meta.from', 101)->assertJsonPath('meta.to', 130)->assertJsonCount(30, 'data.rows');
    }

    public function test_more_than_two_thousand_records_are_all_accessible(): void
    {
        $this->penalties(2100, $this->staying, $this->alpha);

        $this->balances('per_page=200&page=1')->assertOk()->assertJsonPath('meta.total', 2100)->assertJsonPath('meta.last_page', 11)->assertJsonPath('meta.truncated', false)->assertJsonCount(200, 'data.rows');
        $last = $this->balances('per_page=200&page=11')->assertOk();
        $last->assertJsonCount(100, 'data.rows')->assertJsonPath('meta.from', 2001)->assertJsonPath('meta.to', 2100)->assertJsonPath('data.totals.count', 2100)->assertJsonPath('data.totals.owed_cents', 210000);
    }

    public function test_the_default_page_size_is_fifty_and_a_page_past_the_end_returns_the_last_page(): void
    {
        $this->penalties(60, $this->staying, $this->alpha);

        $this->balances()->assertOk()->assertJsonPath('meta.per_page', 50)->assertJsonCount(50, 'data.rows')->assertJsonPath('meta.last_page', 2);
        $this->balances('page=99')->assertOk()->assertJsonPath('meta.current_page', 2)->assertJsonCount(10, 'data.rows');
    }

    public function test_the_page_parameters_are_validated_and_capped(): void
    {
        $this->balances('per_page=201')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->balances('per_page=0')->assertStatus(422)->assertJsonValidationErrors('per_page');
        $this->balances('page=0')->assertStatus(422)->assertJsonValidationErrors('page');
        $this->balances('page=abc')->assertStatus(422)->assertJsonValidationErrors('page');
        $this->balances('per_page=200')->assertOk();
    }

    public function test_the_company_kind_and_leaver_filters_still_work_with_paging_and_report_their_own_totals(): void
    {
        $this->penalties(70, $this->staying, $this->alpha);
        $this->advances(40, $this->staying, $this->alpha);
        $this->penalties(25, $this->leaver, $this->bravo);

        $this->balances('per_page=20')->assertOk()->assertJsonPath('meta.total', 135);
        $this->balances("client_company_id={$this->alpha->id}&per_page=20")->assertJsonPath('meta.total', 110)->assertJsonPath('data.totals.leaver_count', 0);
        $this->balances("client_company_id={$this->bravo->id}&per_page=20")->assertJsonPath('meta.total', 25)->assertJsonPath('data.totals.leaver_count', 25);
        $this->balances('kind=advance&per_page=20')->assertJsonPath('meta.total', 40)->assertJsonPath('data.totals.owed_cents', 8000)->assertJsonPath('meta.last_page', 2);
        $this->balances('kind=penalty&per_page=20')->assertJsonPath('meta.total', 95);
        $this->balances('leavers_only=1&per_page=20')->assertJsonPath('meta.total', 25)->assertJsonPath('data.totals.leaver_owed_cents', 2500)->assertJsonPath('meta.last_page', 2);
        $this->balances("leavers_only=1&kind=advance&client_company_id={$this->bravo->id}")->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data.rows')->assertJsonPath('meta.from', 0);

        // The leaver flag is on the rows of the page too.
        $rows = $this->balances("client_company_id={$this->bravo->id}&per_page=5")->json('data.rows');
        $this->assertSame([true, true, true, true, true], array_column($rows, 'leaver'));
    }

    public function test_a_truncated_scan_is_said_so_instead_of_being_hidden(): void
    {
        $this->penalties(12, $this->staying, $this->alpha);
        $this->advances(3, $this->staying, $this->alpha);

        $this->balances()->assertOk()->assertJsonPath('meta.truncated', false)->assertJsonPath('meta.total', 15);

        DeductionBalanceList::$scanLimit = 10;
        $this->balances()->assertOk()->assertJsonPath('meta.truncated', true)->assertJsonPath('meta.total', 13, '10 penalties examined + 3 advances: the answer never claims to be complete');
        $this->balances('kind=advance')->assertOk()->assertJsonPath('meta.truncated', false)->assertJsonPath('meta.total', 3);
    }

    public function test_the_permission_check_and_the_read_only_nature_are_unchanged(): void
    {
        $this->penalties(3, $this->staying, $this->alpha);
        $before = [EmployeePenalty::query()->count(), EmployeeAdvance::query()->count(), EmployeePenalty::query()->sum('waived_cents')];

        $this->balances('', $this->tokenWithOnly(['hr.view']))->assertForbidden();
        $this->balances('', $this->tokenWithOnly(['hr.payroll.view']))->assertOk()->assertJsonPath('meta.total', 3);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson('/api/v1/admin/hr/payroll/deduction-balances')->assertUnauthorized();
        $this->assertSame($before, [EmployeePenalty::query()->count(), EmployeeAdvance::query()->count(), EmployeePenalty::query()->sum('waived_cents')], 'reading the list changes nothing');
    }
}
