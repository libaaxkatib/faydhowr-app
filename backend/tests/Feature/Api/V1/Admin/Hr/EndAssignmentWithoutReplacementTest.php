<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\AssignmentSuccession;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The generic End route (`PATCH /v1/admin/hr/work-assignments/{id}/end`, used by the End Assignment dialog on the employee page) ends ONE company
 * assignment of a Permanent employee without a replacement (owner decision 2026-11-04). A replacement that is chosen is still validated and carried
 * out. The employee's HR status, the employee's other assignments, payroll lines and the audit trail are untouched. Synthetic data only.
 */
class EndAssignmentWithoutReplacementTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

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

    private function end(string $token, EmployeeWorkAssignment $a, array $body)
    {
        return $this->withToken($token)->patchJson("/api/v1/admin/hr/work-assignments/{$a->id}/end", $body);
    }

    /** @return array<int, mixed> [state of the assignment] */
    private function stateOf(EmployeeWorkAssignment $a): array
    {
        $a->refresh();

        return [$a->status->value, $a->end_date?->toDateString(), $a->notes, $a->client_company_id, $a->salary_amount, $a->start_date->toDateString()];
    }

    public function test_a_permanent_assignment_ends_without_a_successor_and_nothing_else_changes(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('active', 'permanent');
        $alpha = $this->makeAssignment($employee, $this->makeCompany('Alpha Co'), null, 300, '2026-01-01');
        $bravo = $this->makeAssignment($employee, $this->makeCompany('Bravo Co'), null, 200, '2026-02-01');
        $bravoBefore = $this->stateOf($bravo);
        $employees = Employee::query()->count();

        $this->end($token, $alpha, ['end_date' => '2026-10-15', 'note' => 'Contract ended'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ended');

        $this->assertSame(['ended', '2026-10-15', 'Contract ended'], array_slice($this->stateOf($alpha), 0, 3));
        $this->assertSame($bravoBefore, $this->stateOf($bravo), "the employee's other company assignment is untouched");
        $this->assertSame('active', $employee->fresh()->status->value, 'the employee HR status is unchanged');
        $this->assertNull($employee->fresh()->deleted_at);
        $this->assertSame(0, AssignmentSuccession::query()->count(), 'no replacement was recorded or invented');
        $this->assertSame(0, EmployeeWorkAssignment::query()->where('replaces_assignment_id', $alpha->id)->count(), 'nobody was put in the position');
        $this->assertSame($employees, Employee::query()->count());
        $this->assertSame(1, $alpha->salaryHistories()->count(), 'the salary history is kept');
    }

    public function test_the_end_is_audited_and_earlier_payroll_lines_are_kept(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('active', 'permanent');
        $a = $this->makeAssignment($employee, $this->makeCompany('Alpha Co'), null, 300, '2026-01-01');
        $run = PayrollRun::query()->create(['period' => '2026-09', 'status' => 'draft']);
        $item = PayrollItem::query()->create(['payroll_run_id' => $run->id, 'period' => '2026-09', 'employee_id' => $employee->id, 'work_assignment_id' => $a->id, 'employee_name' => 'Synthetic Person', 'employee_number' => $employee->employee_number, 'gross_cents' => 30000, 'net_cents' => 30000, 'eligible_days' => 30, 'days_in_month' => 30, 'calc_snapshot' => []]);

        $this->end($token, $a, ['end_date' => '2026-10-15'])->assertOk();

        $kept = $item->fresh();
        $this->assertSame([30000, 30000, '2026-09'], [(int) $kept->gross_cents, (int) $kept->net_cents, $kept->period], 'the earlier payroll line is exactly as it was');
        $this->assertSame(1, PayrollItem::query()->where('work_assignment_id', $a->id)->count());
        $this->assertSame('draft', $run->fresh()->status, 'no payroll action was triggered');
        $this->assertTrue(
            AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $a->id)->where('description', "Work assignment #{$a->id} ended.")->exists(),
            'the end is in the audit trail',
        );
    }

    public function test_a_successor_that_is_chosen_is_still_validated_and_a_bad_one_changes_nothing(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('active', 'permanent');
        $a = $this->makeAssignment($employee, $this->makeCompany('Alpha Co'), null, 300, '2026-01-01');
        $before = $this->stateOf($a);
        $otherPermanent = $this->makeEmployee('active', 'permanent');

        // A Permanent person is not a covering Temporary.
        $this->end($token, $a, ['end_date' => '2026-10-15', 'successor' => ['type' => 'temporary', 'employee_id' => $otherPermanent->id]])
            ->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_NOT_TEMPORARY');
        // Nobody replaces themselves.
        $this->end($token, $a, ['end_date' => '2026-10-15', 'successor' => ['type' => 'waiting_list', 'employee_id' => $employee->id, 'salary_amount' => 200, 'salary_currency' => 'USD']])
            ->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSOR_SAME_EMPLOYEE');

        $this->assertSame($before, $this->stateOf($a), 'the assignment is still open');
        $this->assertSame(0, AssignmentSuccession::query()->count());
    }

    public function test_a_valid_successor_is_still_carried_out_with_the_end(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->makeAssignment($this->makeEmployee('active', 'permanent'), $this->makeCompany('Alpha Co'), null, 300, '2026-01-01');
        $person = $this->makeEmployee('waiting');

        $this->end($token, $a, ['end_date' => '2026-10-15', 'successor' => ['type' => 'waiting_list', 'employee_id' => $person->id, 'salary_amount' => 250, 'salary_currency' => 'USD']])->assertOk();

        $this->assertSame('ended', $this->stateOf($a)[0]);
        $this->assertSame('end', AssignmentSuccession::query()->where('departing_assignment_id', $a->id)->value('trigger'));
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('replaces_assignment_id', $a->id)->count());
    }

    public function test_the_end_date_is_still_required_and_an_unknown_assignment_is_refused(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->makeAssignment($this->makeEmployee('active', 'permanent'), $this->makeCompany('Alpha Co'), null, 300, '2026-01-01');
        $before = $this->stateOf($a);

        $this->end($token, $a, [])->assertStatus(422)->assertJsonValidationErrors(['end_date'], 'errors');
        $this->assertSame($before, $this->stateOf($a));

        $this->withToken($token)->patchJson('/api/v1/admin/hr/work-assignments/999999/end', ['end_date' => '2026-10-15'])->assertNotFound();
    }
}
