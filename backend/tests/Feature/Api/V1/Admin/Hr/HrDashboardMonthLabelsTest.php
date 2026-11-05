<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\EmployeePenalty;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision O-2 (2026-11-05): LABEL ONLY. The dashboard shows two different months - the calendar month of its financial cards and the payroll month of
 * its Company payroll group - and says which is which. Nothing about how either is chosen or calculated changed: the payroll block still opens on the
 * PREVIOUS month and still honours `payroll_period`. Synthetic data only.
 */
class HrDashboardMonthLabelsTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_calendar_month_is_reported_and_the_payroll_month_still_defaults_to_the_previous_month(): void
    {
        [, $super] = $this->superAdminToken();

        $data = $this->withToken($super)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data');

        $this->assertSame('2026-11', $data['calendar_month'], 'the month the penalty / advance / payment cards count');
        $this->assertSame('2026-10', $data['payroll']['period'], 'the payroll group still opens on the previous month (decision D-5)');
    }

    public function test_an_explicit_payroll_period_is_still_honoured_and_does_not_move_the_calendar_month(): void
    {
        [, $super] = $this->superAdminToken();

        $data = $this->withToken($super)->getJson('/api/v1/admin/hr/dashboard?payroll_period=2026-08')->assertOk()->json('data');

        $this->assertSame('2026-08', $data['payroll']['period']);
        $this->assertSame('2026-11', $data['calendar_month']);
        $this->withToken($super)->getJson('/api/v1/admin/hr/dashboard?payroll_period=2026-13')->assertStatus(422);
    }

    public function test_the_calendar_cards_still_count_by_record_date_and_ignore_the_payroll_month(): void
    {
        [, $super] = $this->superAdminToken();
        $employee = $this->makeEmployee();

        // A penalty DATED in November but charged to the October payroll: it belongs to the November card (calendar month), as before.
        EmployeePenalty::query()->create(['employee_id' => $employee->id, 'penalty_date' => '2026-11-03', 'reason' => 'Late again', 'deduction_amount' => 40, 'currency' => 'USD', 'payroll_period' => '2026-10']);
        // One dated in October: not in the November card.
        EmployeePenalty::query()->create(['employee_id' => $employee->id, 'penalty_date' => '2026-10-20', 'reason' => 'Late twice', 'deduction_amount' => 15, 'currency' => 'USD', 'payroll_period' => '2026-10']);

        $default = $this->withToken($super)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data');
        $other = $this->withToken($super)->getJson('/api/v1/admin/hr/dashboard?payroll_period=2026-06')->assertOk()->json('data');

        $this->assertEquals(40.0, (float) $default['month_penalties_total']);
        $this->assertEquals(40.0, (float) $other['month_penalties_total'], 'changing the payroll month never changes the calendar cards');
    }

    public function test_the_payroll_block_is_still_hidden_without_payroll_view_but_the_calendar_month_is_not_a_payroll_figure(): void
    {
        $viewer = $this->tokenWithOnly(['hr.view', 'hr.dashboard.view']);

        $data = $this->withToken($viewer)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data');

        $this->assertNull($data['payroll']);
        $this->assertSame('2026-11', $data['calendar_month']);
    }
}
