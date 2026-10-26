<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItemDeduction;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * docs/HR_PAYROLL_RULES.md U8: the Temporary's entitlement and the related deduction from the Permanent are worked out from the SAME actual
 * coverage period - the cover's start date to its actual last day, both inclusive - with the covered Permanent's salary in effect on each day over the
 * real number of days of each month. Verified attendance days play no part. The entitlement comes first; the deduction is then the existing approved
 * rule (deduct the month's entitlement once, only after approval, never a day that was already unpaid leave). Synthetic data only.
 */
class TemporaryCoveragePeriodTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2028-06-01 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Employee, 1: EmployeeWorkAssignment} */
    private function position(int $dollars): array
    {
        $permanent = $this->makeEmployee('active', 'permanent');

        return [$permanent, $this->makeAssignment($permanent, $this->makeCompany(fake()->unique()->company()), null, $dollars, '2026-01-01')];
    }

    private function cover(EmployeeWorkAssignment $a, string $start, ?string $end, bool $approved = true): TemporaryReplacement
    {
        return TemporaryReplacement::query()->create([
            'work_assignment_id' => $a->id, 'replacement_employee_id' => $this->makeEmployee('active', 'temporary')->id,
            'start_date' => $start, 'end_date' => $end, 'currency' => 'USD', 'status' => $end === null ? 'active' : 'ended', 'approved_at' => $approved ? now() : null,
        ]);
    }

    /** @return array<string, mixed> the Permanent's payroll line of the month */
    private function payrollLine(string $token, string $period, Employee $permanent): array
    {
        $runId = $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated()->json('data.id');
        $items = $this->withToken($token)->getJson("/api/v1/admin/hr/payroll-runs/{$runId}")->json('data.items');

        return collect($items)->firstWhere('employee_id', $permanent->id);
    }

    private function replacementDeduction(array $line): string
    {
        return (string) collect($line['deductions'])->firstWhere('kind', 'replacement')['amount'];
    }

    private function expected(string $token, TemporaryReplacement $r)
    {
        return $this->withToken($token)->getJson("/api/v1/admin/hr/temporary-replacements/{$r->id}/expected-payment");
    }

    public function test_both_the_start_and_the_end_date_are_inclusive(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position(600);
        $r = $this->cover($a, '2026-09-21', '2026-09-30');

        $this->expected($token, $r)->assertOk()->assertJsonPath('data.counted_days', 10)->assertJsonPath('data.from', '2026-09-21')->assertJsonPath('data.through', '2026-09-30');
        $this->assertSame('200.00', $this->replacementDeduction($this->payrollLine($token, '2026-09', $permanent)));

        // A single-day cover is one day: start and end are the same day and both count.
        [$other, $b] = $this->position(600);
        $one = $this->cover($b, '2026-08-15', '2026-08-15');
        $this->expected($token, $one)->assertOk()->assertJsonPath('data.counted_days', 1)->assertJsonPath('data.expected', '19.35'); // 600 / 31
        $this->assertSame('19.35', $this->replacementDeduction($this->payrollLine($token, '2026-08', $other)));
    }

    public function test_the_entitlement_and_the_deduction_use_the_same_period_whatever_attendance_was_verified(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position(600);
        $r = $this->cover($a, '2026-09-21', '2026-09-30');
        // One verified attendance day and two recorded-only days: attendance, not the period.
        $r->workdays()->create(['work_assignment_id' => $a->id, 'replacement_employee_id' => $r->replacement_employee_id, 'work_date' => '2026-09-22', 'status' => 'verified']);
        $r->workdays()->create(['work_assignment_id' => $a->id, 'replacement_employee_id' => $r->replacement_employee_id, 'work_date' => '2026-09-23', 'status' => 'recorded']);

        $expected = $this->expected($token, $r)->assertOk()->json('data');
        $line = $this->payrollLine($token, '2026-09', $permanent);

        $this->assertSame('200.00', $expected['expected']);
        $this->assertSame($expected['expected'], $this->replacementDeduction($line), 'the deduction equals the entitlement of the same period');
        $this->assertSame($expected['permanent_deduction'], $this->replacementDeduction($line));
    }

    public function test_early_termination_changes_both_the_entitlement_and_the_deduction(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position(600);
        $r = $this->cover($a, '2026-09-21', '2026-09-30');
        $r->update(['status' => 'active']);

        $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$r->id}/end", ['end_date' => '2026-09-25'])->assertOk();

        $this->expected($token, $r->fresh())->assertOk()->assertJsonPath('data.expected', '100.00')->assertJsonPath('data.planned_end_date', '2026-09-30')->assertJsonPath('data.actual_end_date', '2026-09-25');
        $this->assertSame('100.00', $this->replacementDeduction($this->payrollLine($token, '2026-09', $permanent)), 'nothing after the actual end date is deducted');
    }

    public function test_the_real_month_length_is_used_for_28_29_30_and_31_days(): void
    {
        [, $token] = $this->superAdminToken();

        // [period, salary, first, last, entitlement = deduction]
        foreach ([
            ['2027-02', 280, '2027-02-19', '2027-02-28', '100.00'], // 28 days
            ['2028-02', 290, '2028-02-20', '2028-02-29', '100.00'], // 29 days (leap year)
            ['2026-09', 600, '2026-09-21', '2026-09-30', '200.00'], // 30 days
            ['2026-10', 310, '2026-10-26', '2026-10-31', '60.00'], // 31 days
        ] as [$period, $salary, $first, $last, $amount]) {
            [$permanent, $a] = $this->position($salary);
            $r = $this->cover($a, $first, $last);

            $this->expected($token, $r)->assertOk()->assertJsonPath('data.expected', $amount);
            $this->assertSame($amount, $this->replacementDeduction($this->payrollLine($token, $period, $permanent)), $period);
        }
    }

    public function test_a_salary_change_inside_the_period_splits_both_by_the_effective_date(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position(300);
        $a->salaryHistories()->create(['effective_from' => '2026-09-25', 'amount' => 400, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'raise']);
        $r = $this->cover($a, '2026-09-21', '2026-09-30');

        // 4 days at 300 + 6 days at 400 over 30 days = 120.00 - for the entitlement and for the deduction.
        $this->expected($token, $r)->assertOk()->assertJsonPath('data.expected', '120.00');
        $this->assertSame('120.00', $this->replacementDeduction($this->payrollLine($token, '2026-09', $permanent)));
        $this->assertSame([300.0, 400.0], $a->salaryHistories()->orderBy('effective_from')->pluck('amount')->map(fn ($v) => (float) $v)->all(), 'history is intact');
    }

    public function test_a_day_already_unpaid_leave_is_not_deducted_twice_but_stays_in_the_entitlement(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position(600);
        $r = $this->cover($a, '2026-09-21', '2026-09-30');
        EmployeeLeave::query()->create([
            'employee_id' => $permanent->id, 'leave_type' => 'unpaid', 'approval_status' => 'approved',
            'start_date' => '2026-09-21', 'end_date' => '2026-09-22', 'recorded_by' => Admin::factory()->create()->id,
            'decided_by' => Admin::factory()->create()->id, 'decided_at' => now(),
        ]);

        $expected = $this->expected($token, $r)->assertOk();
        $expected->assertJsonPath('data.expected', '200.00')->assertJsonPath('data.permanent_deduction', '160.00');
        $this->assertContains('DEDUCTION_DIFFERS', array_column($expected->json('data.warnings'), 'code'));
        $line = $this->payrollLine($token, '2026-09', $permanent);
        $this->assertSame('160.00', $this->replacementDeduction($line), 'the two leave days are deducted once, as leave');
        $this->assertSame('40.00', (string) collect($line['deductions'])->firstWhere('kind', 'unpaid_leave')['amount']);
    }

    public function test_nothing_is_deducted_before_approval_and_nothing_is_deducted_twice(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position(600);
        $pending = $this->cover($a, '2026-09-21', '2026-09-30', false);
        // A second cover of the same position that overlaps 26-30 September: those days belong to the cover that started first.
        $second = $this->cover($a, '2026-09-26', '2026-10-03');

        $second->update(['approved_at' => null]);
        $this->assertNull(collect($this->payrollLine($token, '2026-09', $permanent)['deductions'])->firstWhere('kind', 'replacement'), 'unapproved: no deduction yet');
        $pending->update(['approved_at' => now()]);
        $second->update(['approved_at' => now()]);

        $line = $this->payrollLine($token, '2026-09', $permanent);
        $this->assertSame('200.00', $this->replacementDeduction($line), 'September: the first cover owns 21-30, the second adds nothing for 26-30');
        $this->assertSame(1, PayrollItemDeduction::query()->where('kind', 'replacement')->where('source_id', $pending->id)->count());
        $this->assertSame(0, PayrollItemDeduction::query()->where('kind', 'replacement')->where('source_id', $second->id)->count(), 'the second cover has no days of its own in September');

        // Regenerating the draft does not duplicate the deduction.
        $this->payrollLine($token, '2026-09', $permanent);
        $this->assertSame(1, PayrollItemDeduction::query()->where('kind', 'replacement')->where('source_id', $pending->id)->count());
    }

    public function test_the_coverage_and_the_calculation_are_kept_for_audit(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent, $a] = $this->position(600);
        $r = $this->cover($a, '2026-09-21', '2026-09-30');
        $r->update(['planned_end_date' => '2026-10-05']);

        $line = $this->payrollLine($token, '2026-09', $permanent);
        $detail = collect($line['deductions'])->firstWhere('kind', 'replacement')['detail'];

        $this->assertSame(10, $detail['covered_days']);
        $this->assertSame(20000, $detail['entitlement_cents']);
        $this->assertSame(20000, $detail['amount_cents']);
        $this->assertSame(['coverage_period', '2026-09-21', '2026-09-30', '2026-10-05', '2026-09-30'], [$detail['coverage']['basis'], $detail['coverage']['from'], $detail['coverage']['through'], $detail['coverage']['planned_end_date'], $detail['coverage']['actual_end_date']]);
        $this->assertSame('2026-10-05', $detail['planned_end_date']);
    }
}
