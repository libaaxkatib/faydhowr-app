<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AssignmentAbsence;
use App\Models\AssignmentCycleEvent;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\RecoveryCorrection;
use App\Support\Hr\AbsenceCycles;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Super Admin Recovery Center: authorization, preview that writes nothing, apply with reason + confirmation + token + idempotency key, audit, verification,
 * blocked states (successor, overlap, inactive employee, absence cycle, locked / reviewed / paid payroll), month scope and rollback.
 */
class RecoveryCenterTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $company;

    private Admin $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
        $this->company = $this->makeCompany('Recovery Co');
        [$this->owner, $this->token] = $this->superAdminToken();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** A Permanent assignment worth $310 a month (October has 31 days: $10 a day), ended on 20 October by mistake. */
    private function endedOct20(?Employee $employee = null): EmployeeWorkAssignment
    {
        $a = $this->makeAssignment($employee ?? $this->makeEmployee(), $this->company, null, 310, '2026-10-01');
        $a->update(['end_date' => '2026-10-20', 'status' => 'ended', 'position_id' => Position::query()->create(['name' => 'Cleaner '.uniqid()])->id]);

        return $a->refresh();
    }

    /** @return array<string, mixed> */
    private function previewOf(string $workflow, EmployeeWorkAssignment $a, array $params = [], ?string $token = null): array
    {
        $response = $this->withToken($token ?? $this->token)->postJson('/api/v1/admin/recovery/preview', ['workflow' => $workflow, 'assignment_id' => $a->id, 'params' => $params]);
        $response->assertOk();

        return $response->json('data');
    }

    private function applyPayload(string $workflow, EmployeeWorkAssignment $a, array $params, string $token, string $key = 'key-0001-abcd', string $reason = 'Ended by mistake in October'): array
    {
        return ['workflow' => $workflow, 'assignment_id' => $a->id, 'params' => $params, 'reason' => $reason, 'confirm' => true, 'idempotency_key' => $key, 'token' => $token];
    }

    /** The auth guard caches the first user it resolves for the whole test: forget it so each request is really made as the token given (null = signed out). */
    private function asToken(?string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $token === null ? $this : $this->withToken($token);
    }

    private function counts(): array
    {
        return [
            'runs' => PayrollRun::query()->count(), 'items' => DB::table('payroll_items')->count(), 'payments' => DB::table('employee_payments')->count(),
            'assignments' => EmployeeWorkAssignment::query()->count(), 'employees' => Employee::query()->count(), 'corrections' => RecoveryCorrection::query()->count(),
        ];
    }

    // ---------------------------------------------------------------- authorization

    public function test_only_super_admin_can_use_any_recovery_endpoint(): void
    {
        $a = $this->endedOct20();
        $hr = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.payroll.manage', 'hr.payroll.view']);
        $sales = $this->noPermissionToken();

        foreach ([['get', '/workflows'], ['get', '/assignments?search=ab'], ['get', "/assignments/{$a->id}"], ['get', '/payroll-preview?month=2026-10'], ['get', '/corrections'], ['get', '/errors']] as [$verb, $path]) {
            $this->asToken(null)->getJson('/api/v1/admin/recovery'.$path)->assertUnauthorized();
            $this->asToken($hr)->getJson('/api/v1/admin/recovery'.$path)->assertForbidden();
            $this->asToken($sales)->getJson('/api/v1/admin/recovery'.$path)->assertForbidden();
            $this->asToken($this->token)->getJson('/api/v1/admin/recovery'.$path)->assertOk();
        }

        $body = ['workflow' => 'reopen_assignment', 'assignment_id' => $a->id, 'params' => []];

        foreach (['preview', 'apply'] as $action) {
            $this->asToken(null)->postJson('/api/v1/admin/recovery/'.$action, $body)->assertUnauthorized();
            $this->asToken($hr)->postJson('/api/v1/admin/recovery/'.$action, $body)->assertForbidden();
            $this->asToken($sales)->postJson('/api/v1/admin/recovery/'.$action, $body)->assertForbidden();
        }

        $this->assertSame('ended', $a->refresh()->status->value);
        $this->assertSame(0, RecoveryCorrection::query()->count());
    }

    // ---------------------------------------------------------------- reopen

    public function test_reopen_preview_writes_nothing_and_shows_the_payroll_effect(): void
    {
        $a = $this->endedOct20();
        $before = $this->counts();
        $audit = AuditLog::query()->count();

        $data = $this->previewOf('reopen_assignment', $a);

        $this->assertTrue($data['can_apply']);
        $this->assertNotEmpty($data['token']);
        $this->assertSame([], $data['blockers']);
        $this->assertTrue($data['verification']['ok']);
        $october = $data['impact']['periods']['2026-10'];
        $line = collect($october['lines'])->firstWhere('assignment_id', $a->id);
        $this->assertSame(20, $line['before_days']);
        $this->assertSame(31, $line['after_days']);
        $this->assertSame(11000, $line['difference_cents']);
        $this->assertArrayHasKey('2026-11', $data['impact']['periods'], 'the months after the end are shown too: the assignment becomes open-ended');

        $this->assertSame($before, $this->counts());
        $this->assertSame($audit, AuditLog::query()->count(), 'a preview leaves no audit row');
        $this->assertSame('ended', $a->refresh()->status->value);
        $this->assertSame('2026-10-20', $a->end_date->toDateString());
    }

    public function test_reopen_apply_keeps_the_id_audits_and_never_touches_payroll(): void
    {
        $a = $this->endedOct20();
        $other = $this->endedOct20();
        $preview = $this->previewOf('reopen_assignment', $a);
        $before = $this->counts();

        $response = $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('reopen_assignment', $a, [], $preview['token']));

        $response->assertCreated()->assertJsonPath('data.workflow', 'reopen_assignment')->assertJsonPath('data.replayed', false)->assertJsonPath('data.assignment_id', $a->id);
        $a->refresh();
        $this->assertSame('active', $a->status->value);
        $this->assertNull($a->end_date);
        $this->assertSame('ended', $other->refresh()->status->value, 'another assignment is untouched');
        $after = $this->counts();
        $this->assertSame($before['runs'], $after['runs']);
        $this->assertSame($before['items'], $after['items'], 'no payroll line is written, regenerated or removed');
        $this->assertSame($before['payments'], $after['payments']);
        $this->assertSame($before['assignments'], $after['assignments'], 'reopened under the same id, nothing created or deleted');
        $this->assertSame($before['corrections'] + 1, $after['corrections']);

        $row = RecoveryCorrection::query()->firstOrFail();
        $this->assertSame($this->owner->id, $row->actor_id);
        $this->assertSame('Ended by mistake in October', $row->reason);
        $this->assertSame('2026-10-20', $row->changes[0]['before']['end_date']);
        $this->assertNotEmpty($row->impact['periods']['2026-10']);
        $this->assertTrue(AuditLog::query()->where('description', 'like', 'Recovery correction applied%')->exists());
        $this->assertTrue(AuditLog::query()->where('description', 'like', "Assignment #{$a->id}%corrected by the Recovery Center%")->exists());
    }

    public function test_applying_twice_with_the_same_key_is_one_correction(): void
    {
        $a = $this->endedOct20();
        $token = $this->previewOf('reopen_assignment', $a)['token'];
        $payload = $this->applyPayload('reopen_assignment', $a, [], $token);

        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $payload)->assertCreated();
        $again = $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $payload);

        $again->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, RecoveryCorrection::query()->count());
        $this->assertSame(1, AuditLog::query()->where('description', 'like', 'Recovery correction applied%')->count());
    }

    public function test_a_new_key_after_the_correction_is_refused_because_the_preview_is_stale(): void
    {
        $a = $this->endedOct20();
        $token = $this->previewOf('reopen_assignment', $a)['token'];

        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('reopen_assignment', $a, [], $token, 'key-aaaa-0001'))->assertCreated();
        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('reopen_assignment', $a, [], $token, 'key-aaaa-0002'))
            ->assertStatus(409)->assertJsonPath('error_code', 'PREVIEW_STALE');

        $this->assertSame(1, RecoveryCorrection::query()->count());
    }

    public function test_a_key_already_used_for_a_different_correction_is_refused(): void
    {
        $a = $this->endedOct20();
        $b = $this->endedOct20();
        $first = $this->previewOf('reopen_assignment', $a)['token'];
        $second = $this->previewOf('reopen_assignment', $b)['token'];

        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('reopen_assignment', $a, [], $first, 'key-shared-01'))->assertCreated();
        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('reopen_assignment', $b, [], $second, 'key-shared-01'))
            ->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame('ended', $b->refresh()->status->value);
    }

    public function test_apply_needs_a_reason_the_confirmation_and_a_valid_token(): void
    {
        $a = $this->endedOct20();
        $token = $this->previewOf('reopen_assignment', $a)['token'];
        $ok = $this->applyPayload('reopen_assignment', $a, [], $token);
        $post = fn (array $p) => $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $p);

        $post([...$ok, 'reason' => 'short'])->assertStatus(422);
        $post([...$ok, 'reason' => ''])->assertStatus(422);
        $post([...$ok, 'confirm' => false])->assertStatus(422);
        $post(array_diff_key($ok, ['confirm' => 1]))->assertStatus(422);
        $post([...$ok, 'idempotency_key' => 'x'])->assertStatus(422);
        $post([...$ok, 'token' => $token.'x'])->assertStatus(422)->assertJsonPath('error_code', 'PREVIEW_TOKEN_INVALID');
        $post([...$ok, 'token' => 'garbage.value'])->assertStatus(422)->assertJsonPath('error_code', 'PREVIEW_TOKEN_INVALID');

        $this->assertSame(0, RecoveryCorrection::query()->count());
        $this->assertSame('ended', $a->refresh()->status->value);
    }

    public function test_a_token_belongs_to_one_admin_and_one_workflow_and_expires(): void
    {
        $a = $this->endedOct20();
        $token = $this->previewOf('reopen_assignment', $a)['token'];
        [, $otherSuper] = $this->superAdminToken();

        $this->asToken($otherSuper)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('reopen_assignment', $a, [], $token))
            ->assertStatus(422)->assertJsonPath('error_code', 'PREVIEW_TOKEN_INVALID');

        CarbonImmutable::setTestNow('2026-11-05 10:20:00');
        $this->asToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('reopen_assignment', $a, [], $token))
            ->assertStatus(409)->assertJsonPath('error_code', 'PREVIEW_EXPIRED');

        $this->assertSame(0, RecoveryCorrection::query()->count());
    }

    public function test_data_that_changed_after_the_preview_is_refused_and_nothing_is_written(): void
    {
        $a = $this->endedOct20();
        $token = $this->previewOf('reopen_assignment', $a)['token'];
        $a->update(['end_date' => '2026-10-25']);

        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('reopen_assignment', $a, [], $token))
            ->assertStatus(409)->assertJsonPath('error_code', 'PREVIEW_STALE');

        $this->assertSame('ended', $a->refresh()->status->value);
        $this->assertSame(0, RecoveryCorrection::query()->count());
    }

    public function test_a_failure_while_applying_rolls_everything_back(): void
    {
        $a = $this->endedOct20();
        $token = $this->previewOf('reopen_assignment', $a)['token'];
        $before = $this->counts();
        $this->withoutExceptionHandling();
        Event::listen(AuditEvent::class, function (): void {
            static $calls = 0;

            if (++$calls >= 2) {
                throw new \RuntimeException('audit store failed');
            }
        });

        try {
            $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('reopen_assignment', $a, [], $token));
            $this->fail('The failure must reach the caller.');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit store failed', $e->getMessage());
        }

        $a->refresh();
        $this->assertSame('ended', $a->status->value);
        $this->assertSame('2026-10-20', $a->end_date->toDateString());
        $this->assertSame($before, $this->counts(), 'the assignment, the correction row and the audit rows are all rolled back together');
    }

    // ---------------------------------------------------------------- blockers

    public function test_reopen_is_blocked_when_a_successor_overlaps_inactive_employee_or_absence_exists(): void
    {
        $a = $this->endedOct20();
        $successor = $this->makeAssignment($this->makeEmployee(), $this->company, null, 310, '2026-10-21');
        $successor->update(['replaces_assignment_id' => $a->id]);
        $data = $this->previewOf('reopen_assignment', $a);
        $this->assertFalse($data['can_apply']);
        $this->assertNull($data['token']);
        $this->assertContains('SUCCESSOR_EXISTS', array_column($data['blockers'], 'code'));

        $employee = $this->makeEmployee();
        $b = $this->endedOct20($employee);
        $this->makeAssignment($employee, $this->company, null, 310, '2026-10-25');
        $this->assertContains('OVERLAPPING_ASSIGNMENT', array_column($this->previewOf('reopen_assignment', $b)['blockers'], 'code'));

        $c = $this->endedOct20($this->makeEmployee('inactive'));
        $this->assertContains('EMPLOYEE_NOT_ACTIVE', array_column($this->previewOf('reopen_assignment', $c)['blockers'], 'code'));

        $d = $this->endedOct20();
        AssignmentAbsence::query()->create(['work_assignment_id' => $d->id, 'last_working_day' => '2026-10-05', 'absent_from' => '2026-10-06', 'returned_on' => '2026-10-10', 'created_by' => $this->owner->id]);
        $this->assertContains('USES_REPLACEMENT_CYCLE', array_column($this->previewOf('reopen_assignment', $d)['blockers'], 'code'));
    }

    public function test_a_blocked_correction_cannot_be_applied_even_with_a_forged_token(): void
    {
        $employee = $this->makeEmployee();
        $a = $this->endedOct20($employee);
        $good = $this->previewOf('reopen_assignment', $this->endedOct20())['token'];
        $this->makeAssignment($employee, $this->company, null, 310, '2026-10-25');

        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('reopen_assignment', $a, [], $good))->assertStatus(409)->assertJsonPath('error_code', 'PREVIEW_STALE');
        $this->assertSame('ended', $a->refresh()->status->value);
        $this->assertSame(0, RecoveryCorrection::query()->count());
    }

    public function test_approved_finalized_reviewed_or_paid_payroll_blocks_the_correction(): void
    {
        foreach (['approved' => 'PAYROLL_LOCKED', 'finalized' => 'PAYROLL_LOCKED', 'reviewed' => 'PAYROLL_REVIEWED'] as $status => $code) {
            $a = $this->endedOct20();
            $run = PayrollRun::query()->create(['period' => '2026-10', 'status' => $status, 'currency' => 'USD']);
            $data = $this->previewOf('reopen_assignment', $a);

            $this->assertFalse($data['can_apply'], $status);
            $this->assertContains($code, array_column($data['blockers'], 'code'), $status);
            $blocker = collect($data['blockers'])->firstWhere('code', $code);
            $this->assertSame('/hr/payroll', $blocker['link'], 'guidance links to the existing process');
            $this->assertSame($status === 'reviewed' ? 'reviewed' : $status, PayrollRun::query()->find($run->id)->status);
            $run->delete();
        }

        // A recorded payment of the employee in the affected months blocks it as well.
        $employee = $this->makeEmployee();
        $b = $this->endedOct20($employee);
        DB::table('employee_payments')->insert(['employee_id' => $employee->id, 'payment_date' => '2026-10-28', 'amount' => 100, 'currency' => 'USD', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertContains('PAYMENT_RECORDED', array_column($this->previewOf('reopen_assignment', $b)['blockers'], 'code'));
        $this->assertSame('ended', $b->refresh()->status->value);
    }

    public function test_a_draft_run_is_never_regenerated_by_a_correction(): void
    {
        $a = $this->endedOct20();
        $run = PayrollRun::query()->create(['period' => '2026-10', 'status' => 'draft', 'currency' => 'USD']);
        $token = $this->previewOf('reopen_assignment', $a)['token'];

        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('reopen_assignment', $a, [], $token))->assertCreated();

        $this->assertSame('draft', $run->refresh()->status);
        $this->assertSame(0, DB::table('payroll_items')->count(), 'the draft is refreshed by the owner pressing Generate, never here');
    }

    // ---------------------------------------------------------------- restore

    public function test_a_cancelled_assignment_can_be_restored_with_its_original_dates(): void
    {
        $a = $this->makeAssignment($this->makeEmployee(), $this->company, null, 310, '2026-10-01');
        $a->update(['status' => 'cancelled']);
        $data = $this->previewOf('restore_cancelled_assignment', $a);
        $this->assertTrue($data['can_apply']);
        $this->assertSame(31, collect($data['impact']['periods']['2026-10']['lines'])->firstWhere('assignment_id', $a->id)['after_days']);

        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('restore_cancelled_assignment', $a, [], $data['token']))->assertCreated();

        $a->refresh();
        $this->assertSame('active', $a->status->value);
        $this->assertSame('2026-10-01', $a->start_date->toDateString());
        $this->assertNull($a->end_date);
    }

    public function test_restoring_an_assignment_that_is_not_cancelled_is_blocked(): void
    {
        $a = $this->endedOct20();

        $this->assertContains('NOT_CANCELLED', array_column($this->previewOf('restore_cancelled_assignment', $a)['blockers'], 'code'));
    }

    // ---------------------------------------------------------------- date corrections

    public function test_the_start_date_can_be_corrected_inside_one_month_and_the_first_salary_row_follows(): void
    {
        $a = $this->makeAssignment($this->makeEmployee(), $this->company, null, 310, '2026-10-10');
        $a->update(['position_id' => Position::query()->create(['name' => 'Guard'])->id]);
        $params = ['month' => '2026-10', 'start_date' => '2026-10-05'];
        $data = $this->previewOf('correct_assignment_dates', $a, $params);

        $this->assertTrue($data['can_apply'], json_encode($data['blockers']));
        $line = collect($data['impact']['periods']['2026-10']['lines'])->firstWhere('assignment_id', $a->id);
        $this->assertSame(22, $line['before_days']);
        $this->assertSame(27, $line['after_days']);
        $this->assertSame([], $data['impact']['periods']['2026-09']['changed_employees'] ?? [], 'the previous month does not change');
        $this->assertSame(['2026-10'], array_keys($data['impact']['periods']), 'only the selected month is shown');

        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('correct_assignment_dates', $a, $params, $data['token']))->assertCreated();

        $this->assertSame('2026-10-05', $a->refresh()->start_date->toDateString());
        $this->assertSame('2026-10-05', $a->salaryHistories()->first()->effective_from->toDateString());
    }

    public function test_the_end_date_can_be_corrected_inside_one_month(): void
    {
        $a = $this->endedOct20();
        $params = ['month' => '2026-10', 'end_date' => '2026-10-24'];
        $data = $this->previewOf('correct_assignment_dates', $a, $params);

        $this->assertTrue($data['can_apply'], json_encode($data['blockers']));
        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('correct_assignment_dates', $a, $params, $data['token']))->assertCreated();

        $this->assertSame('2026-10-24', $a->refresh()->end_date->toDateString());
        $this->assertSame('ended', $a->status->value);
    }

    public function test_a_date_correction_that_leaves_the_month_is_blocked_never_widened(): void
    {
        $a = $this->makeAssignment($this->makeEmployee(), $this->company, null, 310, '2026-10-10');
        $toSeptember = $this->previewOf('correct_assignment_dates', $a, ['month' => '2026-10', 'start_date' => '2026-09-28']);
        $this->assertContains('DATE_OUTSIDE_MONTH', array_column($toSeptember['blockers'], 'code'));
        $this->assertFalse($toSeptember['can_apply']);

        $b = $this->endedOct20();
        $intoNovember = $this->previewOf('correct_assignment_dates', $b, ['month' => '2026-10', 'end_date' => '2026-11-03']);
        $this->assertContains('DATE_OUTSIDE_MONTH', array_column($intoNovember['blockers'], 'code'));

        $wrongMonth = $this->previewOf('correct_assignment_dates', $b, ['month' => '2026-09', 'end_date' => '2026-09-10']);
        $this->assertContains('DATE_OUTSIDE_MONTH', array_column($wrongMonth['blockers'], 'code'), 'the old date is not in the chosen month');

        $this->assertContains('INVALID_MONTH', array_column($this->previewOf('correct_assignment_dates', $b, ['end_date' => '2026-10-22'])['blockers'], 'code'));
        $this->assertSame('2026-10-20', $b->refresh()->end_date->toDateString());
    }

    public function test_impossible_date_corrections_are_blocked(): void
    {
        $a = $this->endedOct20();
        $this->assertContains('END_BEFORE_START', array_column($this->previewOf('correct_assignment_dates', $a, ['month' => '2026-10', 'end_date' => '2026-09-30'])['blockers'], 'code'));
        $this->assertContains('NOTHING_TO_CHANGE', array_column($this->previewOf('correct_assignment_dates', $a, ['month' => '2026-10', 'end_date' => '2026-10-20'])['blockers'], 'code'));

        $open = $this->makeAssignment($this->makeEmployee(), $this->company, null, 310, '2026-10-01');
        $this->assertContains('USE_STOP', array_column($this->previewOf('correct_assignment_dates', $open, ['month' => '2026-10', 'end_date' => '2026-10-15'])['blockers'], 'code'));

        $employee = $this->makeEmployee();
        $mine = $this->endedOct20($employee);
        $this->makeAssignment($employee, $this->company, null, 310, '2026-10-23');
        $this->assertContains('OVERLAPPING_ASSIGNMENT', array_column($this->previewOf('correct_assignment_dates', $mine, ['month' => '2026-10', 'end_date' => '2026-10-26'])['blockers'], 'code'));
    }

    public function test_a_date_correction_in_closed_payroll_is_guidance_only(): void
    {
        $a = $this->endedOct20();
        PayrollRun::query()->create(['period' => '2026-10', 'status' => 'finalized', 'currency' => 'USD']);
        $data = $this->previewOf('correct_assignment_dates', $a, ['month' => '2026-10', 'end_date' => '2026-10-24']);

        $this->assertFalse($data['can_apply']);
        $this->assertContains('PAYROLL_LOCKED', array_column($data['blockers'], 'code'));
        $this->assertSame('2026-10-20', $a->refresh()->end_date->toDateString());
    }

    // ---------------------------------------------------------------- replacement cycle

    /** @return array{0: EmployeeWorkAssignment, 1: EmployeeWorkAssignment, 2: AssignmentAbsence} */
    private function cycle(): array
    {
        $original = $this->makeAssignment($this->makeEmployee(), $this->company, null, 310, '2026-10-01');
        $original->update(['position_id' => Position::query()->create(['name' => 'Cleaner'])->id]);
        $absence = AbsenceCycles::start($original, CarbonImmutable::parse('2026-10-10'), $this->owner);
        $cover = $this->makeAssignment($this->makeEmployee('active', 'temporary'), $this->company, null, 310, '2026-10-11');
        $cover->update(['assignment_type' => 'replacement', 'replaces_assignment_id' => $original->id, 'end_date' => '2026-10-19', 'status' => 'ended', 'position_id' => $original->position_id]);
        AbsenceCycles::event($absence, AssignmentCycleEvent::REPLACEMENT_STARTED, CarbonImmutable::parse('2026-10-11'), $this->owner, $cover->id);
        $absence->update(['returned_on' => '2026-10-20']);
        AbsenceCycles::event($absence, AssignmentCycleEvent::RETURNED, CarbonImmutable::parse('2026-10-20'), $this->owner);

        return [$original->refresh(), $cover->refresh(), $absence->refresh()];
    }

    public function test_the_return_date_of_a_cycle_can_be_corrected_and_the_cover_follows(): void
    {
        [$original, $cover, $absence] = $this->cycle();
        $params = ['month' => '2026-10', 'absence_id' => $absence->id, 'returned_on' => '2026-10-22'];
        $data = $this->previewOf('correct_absence', $original, $params);

        $this->assertTrue($data['can_apply'], json_encode($data['blockers']));
        $this->assertCount(2, $data['changes'], 'the absence and the Replacement that ends the day before the return');
        $line = collect($data['impact']['periods']['2026-10']['lines'])->firstWhere('assignment_id', $original->id);
        $this->assertSame(22, $line['before_days']);
        $this->assertSame(20, $line['after_days'], 'two more days are not paid: the holder returned two days later');

        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('correct_absence', $original, $params, $data['token']))->assertCreated();

        $this->assertSame('2026-10-22', $absence->refresh()->returned_on->toDateString());
        $this->assertSame('2026-10-21', $cover->refresh()->end_date->toDateString());
        $event = AssignmentCycleEvent::query()->where('event_type', AssignmentCycleEvent::WINDOW_CORRECTED)->firstOrFail();
        $this->assertSame('2026-10-20', $event->details['before']['returned_on']);
        $this->assertSame('2026-10-22', $event->details['after']['returned_on']);
        $this->assertSame('Ended by mistake in October', $event->details['reason']);
    }

    public function test_the_last_working_day_of_a_cycle_can_be_corrected_inside_the_month(): void
    {
        [$original, , $absence] = $this->cycle();
        $params = ['month' => '2026-10', 'absence_id' => $absence->id, 'last_working_day' => '2026-10-09'];
        $data = $this->previewOf('correct_absence', $original, $params);

        // The cover started on the 11th; the corrected absence begins on the 10th, so the cover is still inside it.
        $this->assertTrue($data['can_apply'], json_encode($data['blockers']));
        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('correct_absence', $original, $params, $data['token']))->assertCreated();

        $absence->refresh();
        $this->assertSame('2026-10-09', $absence->last_working_day->toDateString());
        $this->assertSame('2026-10-10', $absence->absent_from->toDateString());
    }

    public function test_cycle_corrections_that_break_the_cycle_or_leave_the_month_are_blocked(): void
    {
        [$original, , $absence] = $this->cycle();
        $codes = fn (array $params) => array_column($this->previewOf('correct_absence', $original, ['month' => '2026-10', 'absence_id' => $absence->id, ...$params])['blockers'], 'code');

        $this->assertContains('DATE_OUTSIDE_MONTH', $codes(['returned_on' => '2026-11-02']));
        $this->assertContains('RETURN_NOT_AFTER_ABSENCE', $codes(['returned_on' => '2026-10-11']));
        $this->assertContains('REPLACEMENT_STARTS_BEFORE_ABSENCE', $codes(['last_working_day' => '2026-10-15']));
        $this->assertContains('RETURN_BEFORE_REPLACEMENT_STARTED', $codes(['returned_on' => '2026-10-11']));
        $this->assertContains('NOTHING_TO_CHANGE', $codes([]));
        $this->assertContains('ABSENCE_NOT_FOUND', array_column($this->previewOf('correct_absence', $original, ['month' => '2026-10', 'absence_id' => 999999, 'returned_on' => '2026-10-22'])['blockers'], 'code'));
    }

    public function test_a_plain_date_correction_of_a_cycle_assignment_is_redirected_to_the_cycle_workflow(): void
    {
        [$original, $cover] = $this->cycle();

        $this->assertContains('USES_REPLACEMENT_CYCLE', array_column($this->previewOf('correct_assignment_dates', $original, ['month' => '2026-10', 'start_date' => '2026-10-02'])['blockers'], 'code'));
        $this->assertContains('USES_REPLACEMENT_CYCLE', array_column($this->previewOf('correct_assignment_dates', $cover, ['month' => '2026-10', 'end_date' => '2026-10-18'])['blockers'], 'code'));
    }

    // ---------------------------------------------------------------- read-only helpers

    public function test_the_draft_payroll_preview_compares_and_saves_nothing(): void
    {
        $this->endedOct20();
        $before = $this->counts();

        $response = $this->withToken($this->token)->getJson('/api/v1/admin/recovery/payroll-preview?month=2026-10')->assertOk();

        $this->assertNull($response->json('data.run_status'));
        $this->assertSame(20 * 1000 * 1, $response->json('data.comparison.after_total_cents'));
        $this->assertSame($before, $this->counts());
        $this->withToken($this->token)->getJson('/api/v1/admin/recovery/payroll-preview?month=2026-13')->assertStatus(422);
    }

    public function test_inspect_lists_the_workflows_that_apply_and_their_blockers(): void
    {
        $a = $this->endedOct20();
        $response = $this->withToken($this->token)->getJson("/api/v1/admin/recovery/assignments/{$a->id}")->assertOk();

        $this->assertSame($a->id, $response->json('data.assignment.id'));
        $reopen = collect($response->json('data.workflows'))->firstWhere('id', 'reopen_assignment');
        $this->assertTrue($reopen['available']);
        $this->assertFalse(collect($response->json('data.workflows'))->firstWhere('id', 'restore_cancelled_assignment')['available']);
        $this->withToken($this->token)->getJson('/api/v1/admin/recovery/assignments/999999')->assertNotFound();
    }

    public function test_assignment_search_finds_by_employee_name_number_or_id(): void
    {
        $employee = $this->makeEmployee();
        $a = $this->endedOct20($employee);
        $byName = $this->withToken($this->token)->getJson('/api/v1/admin/recovery/assignments?search='.urlencode(mb_substr($employee->full_name, 0, 5)))->assertOk();
        $this->assertContains($a->id, array_column($byName->json('data'), 'id'));

        $byId = $this->withToken($this->token)->getJson('/api/v1/admin/recovery/assignments?search='.$a->id)->assertOk();
        $this->assertContains($a->id, array_column($byId->json('data'), 'id'));
    }

    public function test_blocked_action_errors_are_explained_in_plain_language_with_a_link(): void
    {
        $byCode = $this->withToken($this->token)->getJson('/api/v1/admin/recovery/errors?q=SUCCESSION_OVERLAP')->assertOk();
        $this->assertSame('SUCCESSION_OVERLAP', $byCode->json('data.match.code'));
        $this->assertNotEmpty($byCode->json('data.match.what_to_do'));

        $byText = $this->withToken($this->token)->getJson('/api/v1/admin/recovery/errors?q='.urlencode('Payroll period locked: PAYROLL_PERIOD_LOCKED'))->assertOk();
        $this->assertSame('/hr/payroll', $byText->json('data.match.link'));
        $this->assertTrue($byText->json('data.match.needs_super_admin'));

        $this->assertNull($this->withToken($this->token)->getJson('/api/v1/admin/recovery/errors?q=NOT_A_REAL_CODE')->json('data.match'));
        $this->assertNotEmpty($this->withToken($this->token)->getJson('/api/v1/admin/recovery/errors')->json('data.entries'));
    }

    public function test_the_correction_history_lists_who_when_and_why(): void
    {
        $a = $this->endedOct20();
        $token = $this->previewOf('reopen_assignment', $a)['token'];
        $this->withToken($this->token)->postJson('/api/v1/admin/recovery/apply', $this->applyPayload('reopen_assignment', $a, [], $token))->assertCreated();

        $rows = $this->withToken($this->token)->getJson("/api/v1/admin/recovery/corrections?assignment_id={$a->id}")->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('reopen_assignment', $rows[0]['workflow']);
        $this->assertSame($this->owner->full_name, $rows[0]['applied_by']);
        $this->assertSame('Ended by mistake in October', $rows[0]['reason']);
    }
}
