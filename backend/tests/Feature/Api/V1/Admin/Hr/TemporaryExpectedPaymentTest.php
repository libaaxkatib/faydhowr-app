<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Models\TemporaryReplacementPayment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * docs/HR_PAYROLL_RULES.md N5, N6, N9, N10, N11: the payment a Temporary employee is EXPECTED to receive counts every calendar day the cover
 * existed (up to its actual last day), with the covered Permanent's salary in effect on each day over the real number of days of each month,
 * rounded once per month. The amount is prefilled; a different amount warns with both figures but is permitted; the calculated amount, the amount
 * paid, the optional reason and the actor are stored. The cost deducted from the Permanent (verified workdays only, A6) is NOT changed by any of
 * this. Synthetic data only.
 */
class TemporaryExpectedPaymentTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-05 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: EmployeeWorkAssignment, 1: Employee} */
    private function position(int $dollars = 600): array
    {
        $permanent = $this->makeEmployee('active', 'permanent');

        return [$this->makeAssignment($permanent, $this->makeCompany(fake()->unique()->company()), null, $dollars, '2026-01-01'), $this->makeEmployee('active', 'temporary')];
    }

    private function cover(EmployeeWorkAssignment $a, Employee $temp, string $start, ?string $end = null): TemporaryReplacement
    {
        return TemporaryReplacement::query()->create([
            'work_assignment_id' => $a->id, 'replacement_employee_id' => $temp->id,
            'start_date' => $start, 'end_date' => $end, 'currency' => 'USD', 'status' => $end === null ? 'active' : 'ended',
        ]);
    }

    private function expected(string $token, TemporaryReplacement $r, ?string $through = null)
    {
        return $this->withToken($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$r->id}/expected-payment".($through ? "?through={$through}" : ''));
    }

    private function pay(string $token, TemporaryReplacement $r, array $body)
    {
        return $this->withToken($token)->postJson("/api/v1/admin/hr/temporary-replacements/{$r->id}/payments", $body + ['payment_date' => '2026-10-01']);
    }

    // ------------------------------------------------------------------ N9, N11: the full assignment period, real month lengths

    public function test_600_over_10_eligible_days_of_a_30_day_month_is_200(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $temp] = $this->position(600);
        $r = $this->cover($a, $temp, '2026-09-21', '2026-09-30');

        $this->expected($token, $r)->assertOk()
            ->assertJsonPath('data.expected', '200.00')
            ->assertJsonPath('data.counted_days', 10)
            ->assertJsonPath('data.months.0.days_in_month', 30)
            ->assertJsonPath('data.months.0.days', 10)
            ->assertJsonPath('data.balance', '200.00');
    }

    public function test_every_calendar_day_of_the_cover_counts_whatever_attendance_was_recorded_and_the_cost_follows_the_same_period(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $temp] = $this->position(600);
        $r = $this->cover($a, $temp, '2026-09-21', '2026-09-30');

        // No workday was recorded at all: the expected payment is still the whole period, and so is the cost deducted from the Permanent (U8).
        $first = $this->expected($token, $r)->assertOk();
        $first->assertJsonPath('data.expected', '200.00')->assertJsonPath('data.permanent_deduction', '200.00');
        $codes = array_column($first->json('data.warnings'), 'code');
        $this->assertNotContains('DEDUCTION_DIFFERS', $codes);
        $this->assertContains('COST_NOT_APPROVED', $codes, 'an unapproved cover is never silently treated as approved');

        // Three verified attendance days change nothing: attendance is a record, the period decides.
        $this->withToken($token)->postJson("/api/v1/admin/hr/temporary-replacements/{$r->id}/workdays", ['dates' => ['2026-09-21', '2026-09-22', '2026-09-23'], 'verify' => true])->assertCreated();
        $this->withToken($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$r->id}/cost?period=2026-09")->assertOk()->assertJsonPath('data.verified_days', 3)->assertJsonPath('data.covered_days', 10)->assertJsonPath('data.amount', '200.00');
        $this->expected($token, $r)->assertOk()->assertJsonPath('data.expected', '200.00')->assertJsonPath('data.permanent_deduction', '200.00');
    }

    public function test_months_of_28_29_30_and_31_days_use_their_real_length_including_leap_february(): void
    {
        CarbonImmutable::setTestNow('2028-06-01 10:00:00');
        [, $token] = $this->superAdminToken(); // after the clock moved: a token minted earlier would already have expired

        // [salary, first day, last day, days in month, eligible days, expected]
        foreach ([
            [280, '2027-02-01', '2027-02-28', 28, 28, '280.00'],
            [280, '2027-02-19', '2027-02-28', 28, 10, '100.00'],
            [290, '2028-02-01', '2028-02-29', 29, 29, '290.00'],
            [290, '2028-02-20', '2028-02-29', 29, 10, '100.00'],
            [600, '2026-09-21', '2026-09-30', 30, 10, '200.00'],
            [310, '2026-10-26', '2026-10-31', 31, 6, '60.00'],
            [310, '2026-10-01', '2026-10-31', 31, 31, '310.00'],
        ] as [$salary, $from, $to, $daysInMonth, $eligible, $expected]) {
            [$a, $temp] = $this->position($salary);
            $r = $this->cover($a, $temp, $from, $to);

            $response = $this->expected($token, $r)->assertOk();
            $this->assertSame([$daysInMonth, $eligible, $expected], [$response->json('data.months.0.days_in_month'), $response->json('data.counted_days'), $response->json('data.expected')], "{$from} to {$to}");
        }
    }

    // ------------------------------------------------------------------ N10: early termination

    public function test_a_cover_ended_early_counts_only_the_days_it_existed_and_keeps_the_planned_end_date(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $temp] = $this->position(600);
        $r = $this->cover($a, $temp, '2026-09-21', '2026-09-30');
        $r->update(['status' => 'active']);

        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$r->id}/end", ['end_date' => '2026-09-25'])->assertOk();

        $r->refresh();
        $this->assertSame(['2026-09-25', '2026-09-30'], [$r->end_date->toDateString(), $r->planned_end_date->toDateString()]);
        $this->expected($token, $r)->assertOk()
            ->assertJsonPath('data.expected', '100.00')
            ->assertJsonPath('data.counted_days', 5)
            ->assertJsonPath('data.through', '2026-09-25')
            ->assertJsonPath('data.planned_end_date', '2026-09-30')
            ->assertJsonPath('data.actual_end_date', '2026-09-25')
            ->assertJsonPath('data.ended_early', true);
    }

    public function test_the_planned_end_date_is_kept_once_and_an_open_ended_cover_has_none(): void
    {
        [$a, $temp] = $this->position();
        $planned = $this->cover($a, $temp, '2026-09-21', '2026-09-30');
        $open = $this->cover($a, $this->makeEmployee('active', 'temporary'), '2026-10-01');

        $planned->endOn('2026-09-25');
        $planned->endOn('2026-09-23');
        $open->endOn('2026-10-03');

        $this->assertSame(['2026-09-23', '2026-09-30'], [$planned->fresh()->end_date->toDateString(), $planned->fresh()->planned_end_date->toDateString()], 'the original plan is kept, not overwritten');
        $this->assertNull($open->fresh()->planned_end_date);
    }

    public function test_the_cover_never_counts_days_after_today_or_after_the_covered_assignment_ended(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $temp] = $this->position(310);
        $running = $this->cover($a, $temp, '2026-10-01');

        // Today is 5 October: five days so far, whatever `through` asks for.
        $this->expected($token, $running)->assertOk()->assertJsonPath('data.counted_days', 5)->assertJsonPath('data.expected', '50.00');
        $this->expected($token, $running, '2026-12-31')->assertOk()->assertJsonPath('data.counted_days', 5);

        // The covered assignment ended on 3 October: nothing counts after it.
        $a->update(['end_date' => '2026-10-03', 'status' => 'ended']);
        $this->expected($token, $running)->assertOk()->assertJsonPath('data.counted_days', 3)->assertJsonPath('data.through', '2026-10-03');
    }

    // ------------------------------------------------------------------ N6: the salary change splits the cover

    public function test_a_salary_change_splits_the_cover_by_its_effective_date_without_touching_the_history(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $temp] = $this->position(300);
        $a->salaryHistories()->create(['effective_from' => '2026-09-25', 'amount' => 400, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'raise']);
        $r = $this->cover($a, $temp, '2026-09-21', '2026-09-30');

        // 4 days at 300 (21-24) + 6 days at 400 (25-30), over 30 days: (1200 + 2400) / 30 = 120.00.
        $response = $this->expected($token, $r)->assertOk();
        $response->assertJsonPath('data.expected', '120.00')->assertJsonPath('data.counted_days', 10);
        $this->assertSame([[4, 30000], [6, 40000]], array_map(fn (array $s): array => [$s['days'], $s['monthly_cents']], $response->json('data.months.0.segments')));
        $this->assertSame([300.0, 400.0], $a->salaryHistories()->orderBy('effective_from')->pluck('amount')->map(fn ($v) => (float) $v)->all(), 'the salary history is untouched');
    }

    public function test_the_months_of_a_cover_are_rounded_once_each_and_added(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $temp] = $this->position(310);
        $r = $this->cover($a, $temp, '2026-09-28', '2026-10-03');

        // September: 3 days of 30 at 310 = 31.00; October: 3 days of 31 = 30.00.
        $response = $this->expected($token, $r)->assertOk();
        $this->assertSame(['31.00', '30.00'], array_column($response->json('data.months'), 'amount'));
        $this->assertSame('61.00', $response->json('data.expected'));
    }

    public function test_overlapping_covers_of_one_position_never_count_a_day_twice(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $first] = $this->position(600);
        $one = $this->cover($a, $first, '2026-09-21', '2026-09-30');
        $two = $this->cover($a, $this->makeEmployee('active', 'temporary'), '2026-09-26', '2026-10-03');

        $this->expected($token, $one)->assertOk()->assertJsonPath('data.counted_days', 10)->assertJsonPath('data.overlap.days', 0);
        $response = $this->expected($token, $two)->assertOk();
        // 26-30 September belong to the cover that started first; the second counts 1-3 October only.
        $response->assertJsonPath('data.counted_days', 3)->assertJsonPath('data.overlap.days', 5);
        $this->assertContains('OVERLAPPING_COVER', array_column($response->json('data.warnings'), 'code'));
    }

    // ------------------------------------------------------------------ N5: prefill, warning, permitted override, audit

    public function test_the_expected_payment_is_prefilled_and_a_matching_payment_is_recorded_with_its_calculation(): void
    {
        [$admin, $token] = $this->superAdminToken();
        [$a, $temp] = $this->position(600);
        $r = $this->cover($a, $temp, '2026-09-21', '2026-09-30');

        $this->expected($token, $r)->assertOk()->assertJsonPath('data.balance', '200.00')->assertJsonPath('data.paid_so_far', '0.00');

        $response = $this->pay($token, $r, ['amount' => 200])->assertCreated();
        $response->assertJsonPath('data.payment_check.differs', false)->assertJsonPath('data.payment_check.warning', null)
            ->assertJsonPath('data.payment_check.calculated_amount', '200.00')->assertJsonPath('data.payment_check.entered_amount', '200.00');
        $payment = TemporaryReplacementPayment::query()->sole();
        $this->assertSame(['200.00', '200.00', null, $admin->id], [(string) $payment->amount, (string) $payment->calculated_amount, $payment->override_reason, $payment->paid_by]);
        $this->assertSame('2026-09-30', $payment->calculation['through']);

        // What was paid is deducted from the next expectation.
        $this->expected($token, $r)->assertOk()->assertJsonPath('data.paid_so_far', '200.00')->assertJsonPath('data.balance', '0.00');
    }

    public function test_a_different_amount_warns_with_both_figures_but_is_permitted_and_audited(): void
    {
        [$admin, $token] = $this->superAdminToken();
        [$a, $temp] = $this->position(600);
        $r = $this->cover($a, $temp, '2026-09-21', '2026-09-30');

        $response = $this->pay($token, $r, ['amount' => 150, 'notes' => 'Paid in two parts', 'override_reason' => 'Advance already paid in cash'])->assertCreated();

        $response->assertJsonPath('data.payment_check.differs', true)
            ->assertJsonPath('data.payment_check.calculated_amount', '200.00')
            ->assertJsonPath('data.payment_check.entered_amount', '150.00')
            ->assertJsonPath('data.payment_check.difference', '50.00 less')
            ->assertJsonPath('data.payment_check.cost_approved', false);
        $this->assertStringContainsString('150.00', $response->json('data.payment_check.warning'));
        $this->assertStringContainsString('200.00', $response->json('data.payment_check.warning'));

        $payment = TemporaryReplacementPayment::query()->sole();
        $this->assertSame(['150.00', '200.00', 'Advance already paid in cash', 'Paid in two parts', $admin->id], [(string) $payment->amount, (string) $payment->calculated_amount, $payment->override_reason, $payment->notes, $payment->paid_by]);
        $this->assertNull($r->fresh()->approved_at, 'recording a payment approves nothing');

        $log = DB::table('audit_logs')->where('entity_type', TemporaryReplacement::class)->where('entity_id', $r->id)->where('description', 'like', 'Payment of%')->first();
        $meta = json_decode($log->metadata, true);
        $this->assertSame(['150.00', '200.00', true, 'Advance already paid in cash'], [$meta['amount'], $meta['calculated_amount'], $meta['differs_from_calculated'], $meta['override_reason']]);
        $this->assertSame($admin->id, (int) $log->admin_id);

        // A higher amount is an override too, with or without a reason.
        $this->pay($token, $r, ['amount' => 260, 'payment_date' => '2026-10-02'])->assertCreated()->assertJsonPath('data.payment_check.differs', true);
        $this->assertSame(2, TemporaryReplacementPayment::query()->count());
    }

    public function test_there_is_no_approval_gate_on_paying_a_temporary_and_paying_approves_nothing(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $temp] = $this->position(600);
        $r = $this->cover($a, $temp, '2026-09-21', '2026-09-30');
        $this->assertNull($r->approved_at);

        $this->pay($token, $r, ['amount' => 50])->assertCreated();
        $this->pay($token, $r, ['amount' => 150, 'payment_date' => '2026-10-02'])->assertCreated();

        $this->assertSame(2, TemporaryReplacementPayment::query()->count());
        $this->assertNull($r->fresh()->approved_at, 'the payment workflow is unchanged: it neither needs nor gives an approval');
    }

    public function test_a_payer_without_payroll_visibility_still_records_the_calculation_but_is_not_shown_it(): void
    {
        $hr = $this->tokenWithOnly(['hr.view', 'hr.manage']);
        [$a, $temp] = $this->position(600);
        $r = $this->cover($a, $temp, '2026-09-21', '2026-09-30');

        $response = $this->pay($hr, $r, ['amount' => 120])->assertCreated();

        $response->assertJsonPath('data.payment_check', null);
        $payment = TemporaryReplacementPayment::query()->sole();
        $this->assertSame('200.00', (string) $payment->calculated_amount, 'the calculation is kept for audit even when the payer may not see it');
        $this->assertArrayNotHasKey('calculated_amount', $response->json('data.payments.0'), 'cost information is not shown to hr.manage alone');
        $this->expected($hr, $r)->assertForbidden();
    }

    public function test_the_expected_payment_is_visible_to_payroll_viewers_only(): void
    {
        [$a, $temp] = $this->position(600);
        $r = $this->cover($a, $temp, '2026-09-21', '2026-09-30');

        $this->expected($this->tokenWithOnly(['hr.payroll.view']), $r)->assertOk()->assertJsonPath('data.expected', '200.00');
        $this->app['auth']->forgetGuards(); // each request below is a different user
        $this->expected($this->tokenWithOnly(['hr.view']), $r)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->expected($this->noPermissionToken(), $r)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenWithOnly(['hr.view']))->postJson("/api/v1/admin/hr/temporary-replacements/{$r->id}/payments", ['payment_date' => '2026-10-01', 'amount' => 10])->assertForbidden();
        $this->assertSame(0, TemporaryReplacementPayment::query()->count());
    }

    public function test_the_payment_is_checked_against_what_was_due_on_the_payment_date(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $temp] = $this->position(600);
        $r = $this->cover($a, $temp, '2026-09-21', '2026-09-30');

        // Paid on 25 September: five days (21-25) were due, 100.00.
        $this->pay($token, $r, ['amount' => 100, 'payment_date' => '2026-09-25'])->assertCreated()
            ->assertJsonPath('data.payment_check.differs', false)->assertJsonPath('data.payment_check.calculated_amount', '100.00');
        // The rest, on 30 September.
        $this->pay($token, $r, ['amount' => 100, 'payment_date' => '2026-09-30'])->assertCreated()
            ->assertJsonPath('data.payment_check.differs', false)->assertJsonPath('data.payment_check.calculated_amount', '100.00');
    }
}
