<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\TemporaryReplacement;
use App\Support\Hr\EmploymentTypeChange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * docs/HR_PAYROLL_RULES.md U1 (final): a Permanent employee cannot be changed to Temporary while they still hold an Active assignment - that would make
 * their payroll line disappear. HR must first end the assignment through the approved workflow (which names the replacement), then the change is
 * allowed and everything already calculated stays as it was. Nothing is converted, ended, replaced or recalculated automatically. Synthetic data only.
 */
class EmploymentTypeChangeTest extends TestCase
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

    /** @return array{0: Employee, 1: EmployeeWorkAssignment} */
    private function position(int $dollars = 300): array
    {
        $permanent = $this->makeEmployee('active', 'permanent');

        return [$permanent, $this->makeAssignment($permanent, $this->makeCompany(fake()->unique()->company()), null, $dollars, '2026-01-01')];
    }

    private function change(string $token, Employee $employee, array $extra = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->putJson("/api/v1/admin/hr/employees/{$employee->id}", ['employment_type' => 'temporary'] + $extra);
    }

    /** @return array<string, mixed>|null the employee's line of the month's (re)generated draft */
    private function line(string $token, string $period, Employee $employee): ?array
    {
        $runId = $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated()->json('data.id');

        return collect($this->withToken($token)->getJson("/api/v1/admin/hr/payroll-runs/{$runId}")->json('data.items'))->firstWhere('employee_id', $employee->id);
    }

    private function stop(string $token, EmployeeWorkAssignment $a, string $lastDay = '2026-10-15')
    {
        return $this->withToken($token)->postJson("/api/v1/admin/hr/client-companies/{$a->client_company_id}/employees/{$a->id}/stop", [
            'end_date' => $lastDay, 'reason' => 'Left the position', 'successor' => $this->waitingSuccessor(),
        ]);
    }

    /** @return array<string, int> counts of the records a rejected change must not touch */
    private function snapshot(): array
    {
        return [
            'assignments' => EmployeeWorkAssignment::query()->count(),
            'salary_rows' => DB::table('assignment_salary_histories')->count(),
            'payroll_items' => PayrollItem::query()->count(),
            'payroll_runs' => DB::table('payroll_runs')->count(),
            'covers' => TemporaryReplacement::query()->count(),
            'cover_payments' => DB::table('temporary_replacement_payments')->count(),
            'employment_type_audits' => DB::table('audit_logs')->where('entity_type', Employee::class)->where('metadata', 'like', '%employment_type%')->count(),
        ];
    }

    public function test_a_direct_change_to_temporary_is_refused_while_a_permanent_assignment_is_active_and_nothing_changes(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->position(300);
        $before = $this->line($token, '2026-09', $employee);
        $snapshot = $this->snapshot();

        $response = $this->change($token, $employee)->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYMENT_TYPE_CHANGE_BLOCKED_ACTIVE_ASSIGNMENT');

        $this->assertSame(EmploymentTypeChange::MESSAGE, $response->json('message'));
        $this->assertStringStartsWith('Shaqaalahaan wuxuu leeyahay assignment Permanent ah oo weli firfircoon.', $response->json('message'));
        $this->assertStringContainsString('Nidaamku si toos ah uma beddelayo shaqada hore.', $response->json('message'));
        $this->assertSame($assignment->id, $response->json('errors.assignments.0.id'), 'the assignment in the way is named');
        $this->assertSame('permanent', $employee->fresh()->employment_type);
        $this->assertSame(['active', null], [$assignment->fresh()->status->value, $assignment->fresh()->end_date?->toDateString()]);
        $this->assertSame($snapshot, $this->snapshot(), 'no assignment, salary, payroll, cover or payment record and no audit entry was added or removed');
        // The pay line did not disappear: regenerating the draft gives exactly the same line.
        $this->assertSame($before['gross'], $this->line($token, '2026-09', $employee)['gross']);
        $this->assertNotNull($this->line($token, '2026-10', $employee));
    }

    public function test_the_api_cannot_get_around_the_rule(): void
    {
        [, $token] = $this->superAdminToken();
        [$withReplacementType, $replacementAssignment] = $this->position(300);
        $replacementAssignment->update(['assignment_type' => 'replacement']);
        [$startsLater, $later] = $this->position(300);
        $later->update(['start_date' => '2026-12-01']);
        [$withOther, $other] = $this->position(300);
        $snapshot = $this->snapshot();

        // Any kind of Active assignment blocks, a future start included, and sending other fields along changes nothing.
        $this->change($token, $withReplacementType)->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYMENT_TYPE_CHANGE_BLOCKED_ACTIVE_ASSIGNMENT');
        $this->change($token, $startsLater)->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYMENT_TYPE_CHANGE_BLOCKED_ACTIVE_ASSIGNMENT');
        $this->change($token, $withOther, ['full_name' => 'Renamed Person', 'notes' => 'sneaky'])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYMENT_TYPE_CHANGE_BLOCKED_ACTIVE_ASSIGNMENT');
        // A different spelling is not a way round it: validation refuses it.
        $this->withToken($token)->putJson("/api/v1/admin/hr/employees/{$withOther->id}", ['employment_type' => 'Temporary'])->assertStatus(422);

        $this->assertSame($withOther->fresh()->full_name === 'Renamed Person', false, 'the rest of the rejected request was not saved either');
        $this->assertSame(['permanent', 'permanent', 'permanent'], [$withReplacementType->fresh()->employment_type, $startsLater->fresh()->employment_type, $withOther->fresh()->employment_type]);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_a_rejected_change_leaves_an_approved_and_paid_month_exactly_as_it_was(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee] = $this->position(300);
        $runId = $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated()->json('data.id');
        foreach (['review', 'approve', 'finalize'] as $step) {
            $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$runId}/{$step}")->assertOk();
        }
        $item = PayrollItem::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->withToken($token)->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/pay", ['payment_date' => '2026-10-02'])->assertOk();
        $stored = $item->fresh()->only(['gross_cents', 'net_cents', 'payment_status']);

        $this->change($token, $employee)->assertStatus(422);

        $this->assertSame($stored, $item->fresh()->only(['gross_cents', 'net_cents', 'payment_status']));
        $this->assertSame('paid', $item->fresh()->payment_status);
    }

    public function test_the_approved_workflow_end_the_assignment_with_a_replacement_then_change_works(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->position(300);
        [, $other] = $this->position(250);

        $this->change($token, $employee)->assertStatus(422);

        // 1. End the earlier assignment through the existing workflow (it names the replacement) ...
        $this->stop($token, $assignment, '2026-10-15')->assertOk();
        $this->assertSame('ended', $assignment->fresh()->status->value);
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('replaces_assignment_id', $assignment->id)->count(), 'someone else now holds the position');

        // 2. ... and only then is the change allowed.
        $this->change($token, $employee)->assertOk()->assertJsonPath('data.employment_type', 'temporary');
        $this->assertSame('temporary', $employee->fresh()->employment_type);
        $this->assertSame('ended', $assignment->fresh()->status->value, 'the history of the earlier assignment is kept');

        // 3. The same person can now work as a Temporary on another position.
        $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', [
            'work_assignment_id' => $other->id, 'replacement_employee_id' => $employee->id, 'start_date' => '2026-10-20',
        ])->assertCreated();
    }

    public function test_history_stays_visible_and_unchanged_after_the_permitted_change(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->position(300);
        $runId = $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-08'])->assertCreated()->json('data.id');
        foreach (['review', 'approve'] as $step) {
            $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$runId}/{$step}")->assertOk();
        }
        $augustStored = PayrollItem::query()->where('employee_id', $employee->id)->firstOrFail()->only(['gross_cents', 'net_cents']);
        $septemberBefore = $this->line($token, '2026-09', $employee);

        $this->stop($token, $assignment, '2026-10-15')->assertOk();
        // October as calculated just before the type change (the days until the last paid day).
        $octoberBefore = $this->line($token, '2026-10', $employee);
        $this->change($token, $employee)->assertOk();

        $this->assertSame($augustStored, PayrollItem::query()->where('payroll_run_id', $runId)->where('employee_id', $employee->id)->firstOrFail()->only(['gross_cents', 'net_cents']), 'the approved month is stored and untouched');
        $this->assertSame($septemberBefore['gross'], $this->line($token, '2026-09', $employee)['gross'], 'an open month is still calculated as Permanent pay');
        $this->assertNotNull($octoberBefore, 'the days worked until the last paid day are still paid');
        $this->assertSame($octoberBefore['gross'], $this->line($token, '2026-10', $employee)['gross']);
        $this->assertNull($this->line($token, '2026-11', $employee), 'after the change there is no Permanent assignment, so no Permanent line');
        $this->assertSame('temporary', $employee->fresh()->employment_type);
    }

    public function test_changes_that_cannot_lose_a_pay_line_are_not_blocked(): void
    {
        [, $token] = $this->superAdminToken();
        $noAssignments = $this->makeEmployee('active', 'permanent');
        [$cancelledOnly, $cancelled] = $this->position(300);
        $cancelled->update(['status' => 'cancelled']);
        [$stays, $active] = $this->position(300);
        $temporaryAlready = $this->makeEmployee('active', 'temporary');

        $this->change($token, $noAssignments)->assertOk();
        $this->change($token, $cancelledOnly)->assertOk();
        // Not a change at all (already Temporary), and unrelated edits of a Permanent with an Active assignment, are fine.
        $this->change($token, $temporaryAlready)->assertOk();
        $this->withToken($token)->putJson("/api/v1/admin/hr/employees/{$stays->id}", ['employment_type' => 'permanent', 'notes' => 'ok'])->assertOk();
        $this->withToken($token)->putJson("/api/v1/admin/hr/employees/{$stays->id}", ['notes' => 'again'])->assertOk();
        $this->assertSame('permanent', $stays->fresh()->employment_type);
        $this->assertSame('active', $active->fresh()->status->value);
    }

    public function test_changing_a_temporary_to_permanent_is_unaffected(): void
    {
        [, $token] = $this->superAdminToken();
        $temporary = $this->makeEmployee('active', 'temporary');

        $this->withToken($token)->putJson("/api/v1/admin/hr/employees/{$temporary->id}", ['employment_type' => 'permanent'])->assertOk();

        $this->assertSame('permanent', $temporary->fresh()->employment_type);
    }
}
