<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Actions\Hr\GeneratePayrollRunAction;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeLeave;
use App\Models\EmployeePenalty;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollItemDeduction;
use App\Models\PayrollRun;
use App\Models\TemporaryReplacement;
use App\Support\Hr\Payroll\PayrollRunCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * "Generate / refresh draft" on production timed out (PHP's 30 s) because the calculation asked the database ~11 questions PER assignment, one after the
 * other, ~10 ms each. The calculation now reads those inputs once for the whole month (PayrollInputs). These tests pin down that:
 *  - it gives EXACTLY the lines, deductions, warnings and totals the per-assignment path gives, on a month that uses every rule;
 *  - the number of queries no longer grows with the number of assignments;
 *  - a failure in the middle of generating still changes nothing (all or nothing), and generating twice never duplicates a line.
 * Every record is fake test data in the per-test in-memory database.
 */
class PayrollGenerationBatchedTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const P = '2026-09';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** One plain permanent employee in their own company. */
    private function permanent(int $dollars = 300, string $start = '2026-01-01'): array
    {
        $employee = $this->makeEmployee('active', 'permanent');

        return [$employee, $this->makeAssignment($employee, $this->makeCompany(fake()->unique()->company()), null, $dollars, $start)];
    }

    private function cover(EmployeeWorkAssignment $assignment, int $from, int $to, bool $approved): TemporaryReplacement
    {
        $temp = $this->makeEmployee('active', 'temporary');

        return TemporaryReplacement::query()->create([
            'work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id,
            'start_date' => sprintf('2026-09-%02d', $from), 'end_date' => sprintf('2026-09-%02d', $to), 'currency' => 'USD', 'status' => 'active', 'approved_at' => $approved ? now() : null,
        ]);
    }

    private function leave(int $employeeId, string $approval, string $from, string $to): void
    {
        EmployeeLeave::query()->create([
            'employee_id' => $employeeId, 'leave_type' => 'unpaid', 'approval_status' => $approval, 'start_date' => $from, 'end_date' => $to,
            'recorded_by' => Admin::factory()->create()->id, 'decided_by' => $approval === 'pending' ? null : Admin::factory()->create()->id, 'decided_at' => $approval === 'pending' ? null : now(),
        ]);
    }

    /** A month that uses every input the calculation reads: covers, penalties, advances, leave, absences, succession, salary changes, adjustments, warnings. */
    private function richMonth(): void
    {
        // Plain people, so the month is big enough for ordering to matter.
        foreach (range(1, 6) as $i) {
            $this->permanent(200 + $i * 10);
        }

        // Approved cover and a pending cover on two different people, an absence inside the approved cover.
        [$covered, $coveredAssignment] = $this->permanent(300);
        $this->cover($coveredAssignment, 4, 7, true);
        EmployeeAttendance::query()->create(['employee_id' => $covered->id, 'date' => '2026-09-05', 'status' => 'absent']);
        EmployeeAttendance::query()->create(['employee_id' => $covered->id, 'date' => '2026-09-20', 'status' => 'absent']);
        [, $pendingCover] = $this->permanent(300);
        $this->cover($pendingCover, 10, 12, false);

        // Penalties and advances (one in a foreign currency), on an employee with two assignments.
        $twoJobs = $this->makeEmployee('active', 'permanent');
        $jobA = $this->makeCompany('Co A');
        $jobB = $this->makeCompany('Co B');
        $this->makeAssignment($twoJobs, $jobA, null, 300, '2026-01-01');
        $this->makeAssignment($twoJobs, $jobB, null, 100, '2026-01-01');
        // Each record belongs to ONE company; one more (no company) is left unowned on purpose so the warning path is compared too.
        EmployeePenalty::query()->create(['employee_id' => $twoJobs->id, 'client_company_id' => $jobA->id, 'penalty_date' => '2026-09-05', 'reason' => 'Late', 'deduction_amount' => 15, 'currency' => 'USD', 'payroll_period' => self::P]);
        EmployeePenalty::query()->create(['employee_id' => $twoJobs->id, 'client_company_id' => $jobB->id, 'penalty_date' => '2026-09-06', 'reason' => 'Foreign', 'deduction_amount' => 9, 'currency' => 'SOS', 'payroll_period' => self::P]);
        EmployeePenalty::query()->create(['employee_id' => $twoJobs->id, 'penalty_date' => '2026-09-07', 'reason' => 'Unowned', 'deduction_amount' => 4, 'currency' => 'USD', 'payroll_period' => self::P]);
        EmployeeAdvance::query()->create(['employee_id' => $twoJobs->id, 'client_company_id' => $jobB->id, 'advance_date' => '2026-09-02', 'amount' => 40, 'currency' => 'USD', 'payroll_period' => self::P, 'reason' => 'Advance']);
        EmployeeAttendance::query()->create(['employee_id' => $twoJobs->id, 'client_company_id' => $jobA->id, 'date' => '2026-09-08', 'status' => 'absent']);

        // Unpaid leave: approved, pending, rejected, and one leave that only touches the month's edge.
        [$leaver] = $this->permanent(300);
        $this->leave($leaver->id, 'approved', '2026-09-10', '2026-09-12');
        $this->leave($leaver->id, 'pending', '2026-09-20', '2026-09-21');
        $this->leave($leaver->id, 'rejected', '2026-09-25', '2026-09-26');
        [$edge] = $this->permanent(300);
        $this->leave($edge->id, 'approved', '2026-08-28', '2026-09-02');

        // A salary change in the middle of the month, and a salary in a currency payroll refuses.
        [, $raised] = $this->permanent(300);
        $raised->salaryHistories()->create(['effective_from' => '2026-09-16', 'amount' => 400, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'Raise']);
        [, $foreign] = $this->permanent(300);
        $foreign->salaryHistories()->create(['effective_from' => '2026-09-10', 'amount' => 900000, 'currency' => 'SOS', 'frequency' => 'monthly', 'reason' => 'Foreign']);

        // Succession: ended predecessor + successor (gap), open predecessor + successor (double-pay warning), a Replacement inside a Permanent, a cancelled successor.
        [, $ended] = $this->permanent(300);
        $ended->update(['end_date' => '2026-09-14', 'status' => 'ended']);
        $this->makeAssignment($this->makeEmployee('active', 'permanent'), $this->makeCompany('Succ A'), null, 300, '2026-09-18')->update(['replaces_assignment_id' => $ended->id]);

        [, $open] = $this->permanent(300);
        $this->makeAssignment($this->makeEmployee('active', 'permanent'), $this->makeCompany('Succ B'), null, 300, '2026-09-15')->update(['replaces_assignment_id' => $open->id]);

        [, $held] = $this->permanent(300);
        $this->makeAssignment($this->makeEmployee('active', 'permanent'), $this->makeCompany('Repl'), null, 250, '2026-09-10')
            ->update(['assignment_type' => 'replacement', 'replaces_assignment_id' => $held->id, 'end_date' => '2026-09-19']);

        [, $notReplaced] = $this->permanent(300);
        $this->makeAssignment($this->makeEmployee('active', 'permanent'), $this->makeCompany('Cancelled'), null, 300, '2026-09-10')
            ->update(['replaces_assignment_id' => $notReplaced->id, 'status' => 'cancelled']);

        // A Temporary worker with an assignment of their own (warning, no line) and an assignment that ended before the month.
        $this->makeAssignment($this->makeEmployee('active', 'temporary'), $this->makeCompany('Temp Co'), null, 150, '2026-01-01');
        [, $old] = $this->permanent(300);
        $old->update(['end_date' => '2026-08-15', 'status' => 'ended']);
        $this->cover($old, 2, 3, true);

        // Money from an earlier month that lands in this one, and a penalty whose employee has no line (orphan warning).
        $admin = Admin::factory()->superAdmin()->create();
        app(GeneratePayrollRunAction::class)->handle('2026-08', $admin);
        PayrollAdjustment::query()->create([
            'payroll_item_id' => PayrollItem::query()->orderBy('id')->firstOrFail()->id, 'kind' => 'correction', 'amount_cents' => 1250,
            'reason' => 'Missed bonus', 'applies_in_period' => self::P, 'status' => 'pending', 'created_by' => $admin->id,
        ]);
        EmployeePenalty::query()->create(['employee_id' => $this->makeEmployee('active', 'permanent')->id, 'penalty_date' => '2026-09-05', 'reason' => 'No line', 'deduction_amount' => 5, 'currency' => 'USD', 'payroll_period' => self::P]);
    }

    public function test_the_batched_calculation_gives_exactly_the_lines_the_per_assignment_calculation_gives(): void
    {
        $this->richMonth();
        $calculator = new PayrollRunCalculator;

        foreach (['2026-09', '2026-10', '2026-08'] as $period) {
            $legacy = $calculator->calculate($period, null, true, false);
            $batched = $calculator->calculate($period);

            $this->assertSame($legacy['totals'], $batched['totals'], "totals of {$period}");
            $this->assertEquals($legacy['warnings'], $batched['warnings'], "warnings of {$period}");
            $this->assertEquals($legacy['items'], $batched['items'], "lines, deductions and snapshots of {$period}");
        }

        // The month really does exercise the rules (otherwise equality would prove nothing).
        $september = $calculator->calculate(self::P);
        $kinds = collect($september['items'])->flatMap(fn (array $i) => collect($i['deductions'])->pluck('kind'))->unique()->sort()->values()->all();
        $this->assertSame(['absence', 'advance', 'penalty', 'replacement', 'unpaid_leave'], $kinds);
        $this->assertGreaterThan(0, collect($september['items'])->sum('adjustment_cents'));
        $this->assertNotEmpty(collect($september['items'])->pluck('calc_snapshot.succession')->filter()->all());
        $this->assertNotEmpty($september['warnings']);
    }

    public function test_the_batched_calculation_matches_for_a_chosen_group_of_employees_and_without_recovery(): void
    {
        $this->richMonth();
        $calculator = new PayrollRunCalculator;
        $ids = EmployeeWorkAssignment::query()->orderBy('id')->limit(14)->pluck('employee_id')->unique()->values()->all();

        $this->assertEquals($calculator->calculate(self::P, $ids, true, false), $calculator->calculate(self::P, $ids));
        $this->assertEquals($calculator->calculate(self::P, $ids, false, false), $calculator->calculate(self::P, $ids, false));
        $this->assertEquals($calculator->calculate(self::P, [], true, false), $calculator->calculate(self::P, []));
    }

    public function test_the_number_of_queries_does_not_grow_with_the_number_of_assignments(): void
    {
        $calculator = new PayrollRunCalculator;
        foreach (range(1, 4) as $i) {
            $this->permanent(300);
        }
        $small = $this->queriesOf(fn () => $calculator->calculate(self::P));
        $smallLegacy = $this->queriesOf(fn () => $calculator->calculate(self::P, null, true, false));

        foreach (range(1, 36) as $i) {
            $this->permanent(300);
        }
        $large = $this->queriesOf(fn () => $calculator->calculate(self::P));
        $largeLegacy = $this->queriesOf(fn () => $calculator->calculate(self::P, null, true, false));

        $this->assertSame($small, $large, 'batched: 4 assignments and 40 assignments cost the same number of queries');
        $this->assertLessThan(40, $large, 'batched: a small, fixed budget');
        $this->assertGreaterThan($smallLegacy + 36 * 5, $largeLegacy, 'the per-assignment path (kept only for this comparison) grows with every assignment');
    }

    public function test_a_failure_in_the_middle_of_generating_changes_nothing(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->richMonth();
        $action = app(GeneratePayrollRunAction::class);
        $run = $action->handle(self::P, $admin);

        $before = $this->snapshotOf($run);

        // The calculation dies after the old lines were already deleted inside the transaction.
        $failing = new GeneratePayrollRunAction(new class extends PayrollRunCalculator
        {
            public function calculate(string $period, ?array $employeeIds = null, bool $withRecovery = true, bool $batched = true): array
            {
                throw new RuntimeException('the request ran out of time');
            }
        });

        try {
            $failing->handle(self::P, $admin);
            $this->fail('the failure must reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('the request ran out of time', $e->getMessage());
        }

        $this->assertSame($before, $this->snapshotOf($run->refresh()), 'lines, deductions, totals and audit trail are exactly as before the failed attempt');
    }

    public function test_generating_again_replaces_the_lines_and_never_duplicates_them(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->richMonth();
        $action = app(GeneratePayrollRunAction::class);

        $first = $this->snapshotOf($action->handle(self::P, $admin), withIds: false);
        $second = $this->snapshotOf($action->handle(self::P, $admin), withIds: false);
        $third = $this->snapshotOf($action->handle(self::P, $admin), withIds: false);

        $this->assertSame($first['items'], $second['items']);
        $this->assertSame($first['items'], $third['items']);
        $this->assertSame($first['deductions'], $third['deductions']);
        $this->assertSame(1, PayrollRun::query()->where('period', self::P)->count());
        $this->assertSame($first['item_count'], PayrollItem::query()->where('period', self::P)->count());
        $this->assertSame(0, PayrollItemDeduction::query()->whereNotIn('payroll_item_id', PayrollItem::query()->select('id'))->count(), 'no deduction is left without its line');
        $this->assertSame(3, AuditLog::query()->where('entity_type', PayrollRun::class)->where('description', 'like', 'Payroll draft for 2026-09 generated%')->count(), 'one audit entry per successful generation');
    }

    public function test_the_applied_adjustment_goes_back_to_pending_and_is_applied_once_when_the_draft_is_regenerated(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->richMonth();
        $action = app(GeneratePayrollRunAction::class);

        $action->handle(self::P, $admin);
        $action->handle(self::P, $admin);

        $adjustment = PayrollAdjustment::query()->firstOrFail();
        $this->assertSame('applied', $adjustment->status);
        $this->assertSame(1, PayrollItem::query()->where('id', $adjustment->applied_in_item_id)->where('period', self::P)->count());
        $this->assertSame(1250, (int) PayrollItem::query()->where('period', self::P)->sum('adjustment_cents'));
    }

    /** @return array{items: list<array<string, mixed>>, deductions: list<array<string, mixed>>, totals: mixed, item_count: int, audit: int} */
    private function snapshotOf(PayrollRun $run, bool $withIds = true): array
    {
        $items = PayrollItem::query()->where('payroll_run_id', $run->id)->orderBy('work_assignment_id')->get();

        return [
            'items' => $items->map(fn (PayrollItem $i): array => collect($i->only(['employee_id', 'work_assignment_id', 'gross_cents', 'replacement_deduction_cents', 'other_deduction_cents', 'adjustment_cents', 'net_cents']))->when($withIds, fn ($c) => $c->put('id', $i->id))->all())->all(),
            'deductions' => PayrollItemDeduction::query()->whereIn('payroll_item_id', $items->pluck('id'))->orderBy('source_type')->orderBy('source_id')->orderBy('kind')->get()->map(fn (PayrollItemDeduction $d): array => collect($d->only(['kind', 'source_type', 'source_id', 'amount_cents']))->when($withIds, fn ($c) => $c->put('payroll_item_id', $d->payroll_item_id))->all())->all(),
            'totals' => $run->totals,
            'item_count' => $items->count(),
            'audit' => AuditLog::query()->where('entity_type', PayrollRun::class)->count(),
        ];
    }

    private function queriesOf(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
