<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\EmployeeAdvance;
use App\Models\EmployeePenalty;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decisions of 2026-11-05 on the penalty / advance validation: NO future-dated record (Option A, the earlier 31-day allowance is gone), applied the
 * same way to penalties, advances and corrections; and the limits the owner explicitly KEPT are still enforced: date from 2020-01-01, payroll month from
 * 2020-01 up to next month, amount above 0 and at most 1,000,000, reason of 3-500 characters. Synthetic data only.
 */
class PenaltyAdvanceValidationLimitsTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $token;

    private int $employeeId;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00'); // 13:00 in Mogadishu, the same calendar day
        [, $this->token] = $this->superAdminToken();
        $this->employeeId = $this->makeEmployee()->id;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function penalty(array $override = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->postJson("/api/v1/admin/hr/employees/{$this->employeeId}/penalties", [
            'penalty_date' => '2026-11-05', 'reason' => 'Late again', 'deduction_amount' => 10, 'currency' => 'USD', 'payroll_period' => '2026-11', ...$override,
        ]);
    }

    private function advance(array $override = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->postJson("/api/v1/admin/hr/employees/{$this->employeeId}/advances", [
            'advance_date' => '2026-11-05', 'reason' => 'Rent', 'amount' => 10, 'currency' => 'USD', 'payroll_period' => '2026-11', ...$override,
        ]);
    }

    // ------------------------------------------------------------------------------------------------ no future date

    public function test_today_and_past_dates_are_accepted_and_any_future_date_is_refused_for_penalties_and_advances(): void
    {
        $this->penalty(['penalty_date' => '2026-11-05'])->assertCreated();                                   // today
        $this->penalty(['penalty_date' => '2026-11-04', 'reason' => 'Yesterday'])->assertCreated();          // past
        $this->penalty(['penalty_date' => '2026-10-01', 'reason' => 'Last month', 'payroll_period' => '2026-10'])->assertCreated();
        $this->advance(['advance_date' => '2026-11-05'])->assertCreated();
        $this->advance(['advance_date' => '2026-11-04', 'reason' => 'Yesterday'])->assertCreated();

        foreach (['2026-11-06', '2026-11-20', '2026-12-05', '2026-12-06', '2027-01-01', '2062-01-01'] as $future) {
            $this->penalty(['penalty_date' => $future])->assertStatus(422)->assertJsonValidationErrors('penalty_date');
            $this->advance(['advance_date' => $future])->assertStatus(422)->assertJsonValidationErrors('advance_date');
        }

        $this->assertSame(3, EmployeePenalty::query()->count());
        $this->assertSame(2, EmployeeAdvance::query()->count());
    }

    public function test_the_previous_31_day_allowance_no_longer_exists(): void
    {
        // 2026-12-06 was the last accepted day under the old rule (31 days ahead); it is refused now, and so is the very next day.
        $this->penalty(['penalty_date' => '2026-12-06'])->assertStatus(422)->assertJsonValidationErrors('penalty_date');
        $this->advance(['advance_date' => '2026-11-06'])->assertStatus(422)->assertJsonValidationErrors('advance_date');
    }

    public function test_today_is_the_business_day_of_mogadishu_not_the_servers_utc_day(): void
    {
        // 22:30 UTC on 5 November is 01:30 on 6 November in Mogadishu: the 6th is "today" there, the 7th is the future.
        CarbonImmutable::setTestNow('2026-11-05 22:30:00');

        $this->penalty(['penalty_date' => '2026-11-06'])->assertCreated();
        $this->penalty(['penalty_date' => '2026-11-07', 'reason' => 'Tomorrow'])->assertStatus(422)->assertJsonValidationErrors('penalty_date');
    }

    public function test_a_correction_cannot_move_a_record_into_the_future_either(): void
    {
        $this->penalty()->assertCreated();
        $this->advance()->assertCreated();
        $penalty = EmployeePenalty::query()->firstOrFail();
        $advance = EmployeeAdvance::query()->firstOrFail();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson("/api/v1/admin/hr/employees/{$this->employeeId}/penalties/{$penalty->id}/correct", ['penalty_date' => '2026-11-06', 'correction_reason' => 'Wrong date', 'expected_version' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('penalty_date');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson("/api/v1/admin/hr/employees/{$this->employeeId}/advances/{$advance->id}/correct", ['advance_date' => '2027-01-01', 'correction_reason' => 'Wrong date', 'expected_version' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('advance_date');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson("/api/v1/admin/hr/employees/{$this->employeeId}/penalties/{$penalty->id}/correct", ['penalty_date' => '2026-11-03', 'correction_reason' => 'Wrong date', 'expected_version' => 1])
            ->assertCreated();

        $this->assertSame('active', $advance->fresh()->status);
        $this->assertSame('voided', $penalty->fresh()->status);
    }

    // ------------------------------------------------------------------------------------------------ the limits the owner kept

    public function test_the_minimum_date_is_2020_01_01(): void
    {
        $this->penalty(['penalty_date' => '2020-01-01', 'payroll_period' => '2020-01'])->assertCreated();
        $this->advance(['advance_date' => '2020-01-01', 'payroll_period' => '2020-01'])->assertCreated();
        $this->penalty(['penalty_date' => '2019-12-31', 'payroll_period' => '2020-01', 'reason' => 'Too old'])->assertStatus(422)->assertJsonValidationErrors('penalty_date');
        $this->advance(['advance_date' => '2019-12-31', 'payroll_period' => '2020-01', 'reason' => 'Too old'])->assertStatus(422)->assertJsonValidationErrors('advance_date');
    }

    public function test_the_payroll_month_runs_from_2020_01_through_next_month_only(): void
    {
        $this->penalty(['penalty_date' => '2026-11-05', 'payroll_period' => '2026-12'])->assertCreated();          // next month
        $this->advance(['advance_date' => '2026-11-05', 'payroll_period' => '2026-12'])->assertCreated();
        $this->penalty(['payroll_period' => '2027-01', 'reason' => 'Two months ahead'])->assertStatus(422)->assertJsonValidationErrors('payroll_period');
        $this->advance(['payroll_period' => '2027-01', 'reason' => 'Two months ahead'])->assertStatus(422)->assertJsonValidationErrors('payroll_period');
        $this->penalty(['penalty_date' => '2020-01-01', 'payroll_period' => '2019-12', 'reason' => 'Before 2020'])->assertStatus(422)->assertJsonValidationErrors('payroll_period');
        $this->advance(['advance_date' => '2020-01-01', 'payroll_period' => '2019-12', 'reason' => 'Before 2020'])->assertStatus(422)->assertJsonValidationErrors('payroll_period');
        $this->penalty(['payroll_period' => '2026-13', 'reason' => 'Not a month'])->assertStatus(422)->assertJsonValidationErrors('payroll_period');
    }

    public function test_the_amount_must_be_above_zero_and_at_most_one_million(): void
    {
        $this->penalty(['deduction_amount' => 0.01])->assertCreated();
        $this->penalty(['deduction_amount' => 1000000, 'reason' => 'Maximum'])->assertCreated();
        $this->advance(['amount' => 0.01])->assertCreated();
        $this->advance(['amount' => 1000000, 'reason' => 'Maximum'])->assertCreated();

        foreach ([0, -1, 1000000.01, 1000001, 12.345] as $bad) {
            $this->penalty(['deduction_amount' => $bad, 'reason' => 'Bad amount'])->assertStatus(422)->assertJsonValidationErrors('deduction_amount');
            $this->advance(['amount' => $bad, 'reason' => 'Bad amount'])->assertStatus(422)->assertJsonValidationErrors('amount');
        }
    }

    public function test_the_reason_must_be_three_to_five_hundred_characters(): void
    {
        $this->penalty(['reason' => 'abc'])->assertCreated();
        $this->penalty(['reason' => str_repeat('x', 500), 'deduction_amount' => 11])->assertCreated();
        $this->advance(['reason' => 'abc'])->assertCreated();
        $this->advance(['reason' => str_repeat('x', 500), 'amount' => 11])->assertCreated();

        foreach (['', 'ab', '  ab  ', str_repeat('x', 501)] as $bad) {
            $this->penalty(['reason' => $bad, 'deduction_amount' => 12])->assertStatus(422)->assertJsonValidationErrors('reason');
            $this->advance(['reason' => $bad, 'amount' => 12])->assertStatus(422)->assertJsonValidationErrors('reason');
        }
    }
}
