<?php

namespace Tests\Feature\Hr;

use App\Enums\WorkAssignmentStatus;
use App\Models\Admin;
use App\Models\AssignmentAbsence;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\TemporaryReplacement;
use App\Support\Hr\MonthBaseline\MonthBaselineRestorer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Month baseline restart (hr:restore-month-baseline): October 2026 goes back to the approved Excel baseline - every baseline Permanent assignment open
 * from 1 October under its own id - and the entries made after it are retired (cancelled, never deleted). The scenario mirrors production: two 4SEASON
 * employees whose October was edited into old-shape chains (ended original, Replacement, returned Permanent), plus one owner-confirmed entry.
 */
class RestoreMonthBaselineCommandTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $company;

    private Position $cleaner;

    private Admin $owner;

    private string $token;

    /** @var array<string, EmployeeWorkAssignment> */
    private array $a = [];

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
        $this->company = $this->makeCompany('4SEASON RES');
        $this->cleaner = Position::query()->create(['name' => 'Cleaner']);
        [$this->owner, $this->token] = $this->superAdminToken();
        $this->csv = tempnam(sys_get_temp_dir(), 'baseline').'.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Builds the October 2026 production-shaped state: baseline rows for mohamed, zakariye, ibrahim; October edits on the first two; a kept entry. */
    private function october(): void
    {
        $e = fn () => $this->makeEmployee();
        $mk = function (string $key, Employee $emp, string $start, ?string $end, ?string $type, ?int $replaces, int $salary = 140, string $status = 'active') {
            $row = $this->makeAssignment($emp, $this->company, null, $salary, $start);
            $row->update(['position_id' => $this->cleaner->id, 'end_date' => $end, 'status' => $status, 'assignment_type' => $type ?? 'permanent', 'replaces_assignment_id' => $replaces]);
            $this->a[$key] = $row->refresh();
        };

        $mohamed = $e();
        $zakariye = $e();
        $ibrahim = $e();
        $adan = $e();
        $suudi = $e();
        $kept = $e();

        // Baseline rows (all Permanent, 2026-10-01, open) as imported ...
        $mk('mohamed', $mohamed, '2026-10-01', '2026-10-11', null, null, 140, 'ended');
        $mk('zakariye', $zakariye, '2026-10-01', '2026-10-12', null, null, 130, 'ended');
        $mk('ibrahim', $ibrahim, '2026-10-01', null, null, null, 130);
        // ... and what HR entered in October (the old shape: Replacement, then the person returns as a new Permanent row).
        $mk('adan', $adan, '2026-10-12', '2026-10-12', 'replacement', $this->a['mohamed']->id, 140, 'ended');
        $mk('mohamed_back', $mohamed, '2026-10-13', null, null, $this->a['adan']->id, 140);
        $mk('suudi', $suudi, '2026-10-13', '2026-10-16', 'replacement', $this->a['zakariye']->id, 130, 'ended');
        $mk('zakariye_back', $zakariye, '2026-10-17', null, null, $this->a['suudi']->id, 130);
        // An entry the owner made by hand that IS in the Excel (confirmed): kept.
        $mk('kept', $kept, '2026-10-01', null, null, null, 130);
    }

    /** @param  list<string>  $keys */
    private function writeBaseline(array $keys = ['mohamed', 'zakariye', 'ibrahim'], string $start = '2026-10-01'): void
    {
        $lines = ['source_row,employee_id,employee_number,name_key,expected_status,company,salary,start_date'];

        foreach ($keys as $i => $key) {
            $row = $this->a[$key];
            $lines[] = implode(',', [$i + 2, $row->employee_id, $row->employee->employee_number, 'x', 'active', '"'.$this->company->name.'"', (int) $row->salary_amount, $start]);
        }

        file_put_contents($this->csv, implode("\n", $lines)."\n");
    }

    private function restart(array $over = [], ?int $exit = null)
    {
        $args = array_replace(['--month' => '2026-10', '--baseline' => [$this->csv], '--keep-assignment' => [$this->a['kept']->id]], $over);
        $command = $this->artisan('hr:restore-month-baseline', $args);

        return $exit === null ? $command->run() : $command->assertExitCode($exit);
    }

    private function hash(): string
    {
        return $this->plan()['plan_hash'];
    }

    private function plan(array $over = []): array
    {
        $rows = array_map(fn (string $l): array => array_combine(['source_row', 'employee_id', 'employee_number', 'name_key', 'expected_status', 'company', 'salary', 'start_date'], str_getcsv($l, ',', '"', '\\')), array_slice(file($this->csv, FILE_IGNORE_NEW_LINES), 1));

        return app(MonthBaselineRestorer::class)->plan('2026-10', $rows, $over['keep'] ?? [$this->a['kept']->id]);
    }

    private function commit(array $over = [], ?int $exit = 0)
    {
        return $this->restart(array_replace(['--commit' => true, '--admin-email' => $this->owner->email, '--reason' => 'Owner restarted October from the Excel baseline.', '--confirm' => $this->hash()], $over), $exit);
    }

    private function generateDraft(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertSuccessful();
    }

    /** @return array<string, string> */
    private function fingerprint(): array
    {
        return [
            'employees' => Employee::query()->withTrashed()->orderBy('id')->get()->toJson(),
            'companies' => ClientCompany::query()->orderBy('id')->get()->toJson(),
            'assignments' => EmployeeWorkAssignment::query()->orderBy('id')->get()->toJson(),
            'histories' => DB::table('assignment_salary_histories')->orderBy('id')->get()->toJson(),
            'runs' => PayrollRun::query()->orderBy('id')->get()->toJson(),
            'items' => PayrollItem::query()->orderBy('id')->get()->toJson(),
            'audit' => (string) AuditLog::query()->count(),
        ];
    }

    // ------------------------------------------------------------------ dry run

    public function test_a_dry_run_writes_nothing_and_prints_a_plan_hash(): void
    {
        $this->october();
        $this->writeBaseline();
        $this->generateDraft();
        $before = $this->fingerprint();

        $this->restart([], 0);

        $this->assertSame($before, $this->fingerprint(), 'every table is byte-identical after a dry run, audit log included');
        $plan = $this->plan();
        $this->assertEquals(['intact' => 1, 'reopen' => 2, 'keep' => 1, 'retire' => 4], $plan['summary']);
        $this->assertSame([], $plan['blockers']);
    }

    // ------------------------------------------------------------------ the restart

    public function test_the_restart_reopens_baseline_assignments_under_their_own_ids_retires_the_changes_and_leaves_everything_else(): void
    {
        $this->october();
        $this->writeBaseline();
        $employees = Employee::query()->orderBy('id')->get()->toJson();
        $ibrahim = EmployeeWorkAssignment::query()->findOrFail($this->a['ibrahim']->id)->toJson();
        $kept = EmployeeWorkAssignment::query()->findOrFail($this->a['kept']->id)->toJson();

        $this->commit();

        foreach (['mohamed', 'zakariye', 'ibrahim', 'kept'] as $key) {
            $row = EmployeeWorkAssignment::query()->findOrFail($this->a[$key]->id);
            $this->assertSame('active', $row->status->value, $key);
            $this->assertNull($row->end_date, $key);
            $this->assertSame('2026-10-01', $row->start_date->toDateString(), $key);
        }

        foreach (['adan', 'mohamed_back', 'suudi', 'zakariye_back'] as $key) {
            $this->assertSame('cancelled', EmployeeWorkAssignment::query()->findOrFail($this->a[$key]->id)->status->value, "{$key} is retired, not deleted");
        }

        $this->assertSame(8, EmployeeWorkAssignment::query()->count(), 'no row was created or deleted');
        $this->assertSame($employees, Employee::query()->orderBy('id')->get()->toJson(), 'employee records are untouched');
        $this->assertSame($ibrahim, EmployeeWorkAssignment::query()->findOrFail($this->a['ibrahim']->id)->toJson(), 'an intact baseline assignment is not touched');
        $this->assertSame($kept, EmployeeWorkAssignment::query()->findOrFail($this->a['kept']->id)->toJson(), 'the owner-confirmed entry is left exactly as it is');
        $this->assertSame(1, DB::table('assignment_salary_histories')->where('work_assignment_id', $this->a['mohamed']->id)->count(), 'salary history is kept');

        $events = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->get()->filter(fn ($l) => str_starts_with((string) ($l->metadata['event'] ?? ''), 'month_'));
        $this->assertCount(6, $events, 'one audit event per changed assignment');
        $this->assertCount(1, $events->pluck('metadata.batch')->unique());
        $this->assertNotNull(AuditLog::query()->get()->first(fn ($l) => ($l->metadata['event'] ?? null) === 'month_baseline_restart'), 'and one summary event');
        $this->assertSame($this->owner->id, $events->first()->admin_id);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->october();
        $this->writeBaseline();
        $this->commit();
        $after = $this->fingerprint();

        $this->commit(['--confirm' => $this->hash()]);

        $this->assertSame(array_diff_key($after, ['audit' => 1]), array_diff_key($this->fingerprint(), ['audit' => 1]), 'a second run changes no data');
        $this->assertEquals(['intact' => 3, 'keep' => 1], $this->plan()['summary']);
    }

    // ------------------------------------------------------------------ payroll

    public function test_the_payroll_preview_is_the_production_calculation_and_matches_the_baseline_salaries(): void
    {
        $this->october();
        $this->writeBaseline();
        $this->generateDraft();
        $currentLines = PayrollItem::query()->where('period', '2026-10')->count();

        $dry = app(MonthBaselineRestorer::class)->dryRun($this->plan());

        $this->assertTrue($dry['verification']['ok'], json_encode($dry['verification']['problems']));
        $payroll = $dry['payroll'];
        $this->assertSame($currentLines, $payroll['current_lines']);
        // 4 employees open for the whole of October: 140 + 130 + 130 + 130 (kept), each a full 31/31 month, no cent lost.
        $this->assertSame(53000, $payroll['proposed_total_cents']);
        $this->assertSame(4, $payroll['proposed_lines']);
        $this->assertSame(40000, $payroll['expected_baseline_cents'], 'the three baseline salaries alone');
        $this->assertCount(PayrollItem::query()->where('period', '2026-10')->count(), array_filter($payroll['lines'], fn ($l) => $l['current_net_cents'] !== null), 'the draft in the database was not touched by the preview');
        $this->assertSame($currentLines, PayrollItem::query()->where('period', '2026-10')->count());
    }

    public function test_the_restart_has_no_way_to_regenerate_approve_or_pay_the_draft(): void
    {
        $this->october();
        $this->writeBaseline();
        $this->generateDraft();
        $before = PayrollRun::query()->where('period', '2026-10')->firstOrFail();

        $this->assertArrayNotHasKey('regenerate-draft', Artisan::all()['hr:restore-month-baseline']->getDefinition()->getOptions(), 'the option does not exist');
        $this->commit();

        $run = PayrollRun::query()->where('period', '2026-10')->firstOrFail();
        $this->assertSame('draft', $run->status);
        $this->assertNull($run->approved_at);
        $this->assertNull($run->finalized_at);
        $this->assertSame($before->generated_at?->toIso8601String(), $run->generated_at?->toIso8601String(), 'the draft was not regenerated');
        $this->assertSame(0, $run->items()->where('payment_status', '!=', 'unpaid')->count());
    }

    public function test_without_regeneration_the_draft_is_left_stale_for_the_owner_to_refresh(): void
    {
        $this->october();
        $this->writeBaseline();
        $this->generateDraft();
        $lines = PayrollItem::query()->orderBy('id')->get()->toJson();

        $this->commit();

        $this->assertSame($lines, PayrollItem::query()->orderBy('id')->get()->toJson());
    }

    public function test_approved_finalized_or_paid_payroll_blocks_the_restart_and_nothing_changes(): void
    {
        $this->october();
        $this->writeBaseline();
        $this->generateDraft();
        $run = PayrollRun::query()->firstOrFail();
        $before = $this->fingerprint();

        foreach (['approved', 'finalized'] as $status) {
            $run->update(['status' => $status]);
            $plan = $this->plan();
            $this->assertSame(['PAYROLL_LOCKED'], array_column($plan['blockers'], 'code'), $status);
            $this->restart([], 1);
            $this->commit(['--confirm' => $plan['plan_hash']], 1);
        }

        $run->update(['status' => 'reviewed']);
        $this->assertSame(['PAYROLL_REVIEWED'], array_column($this->plan()['blockers'], 'code'));

        $run->update(['status' => 'draft']);
        PayrollItem::query()->first()->update(['payment_status' => 'paid']);
        $this->assertContains('PAYMENT_RECORDED', array_column($this->plan()['blockers'], 'code'));
        $this->commit(['--confirm' => $this->hash()], 1);

        $this->assertSame(array_diff_key($before, ['runs' => 1, 'items' => 1]), array_diff_key($this->fingerprint(), ['runs' => 1, 'items' => 1]), 'no assignment, history or audit row changed');
    }

    // ------------------------------------------------------------------ scope: one month only

    public function test_other_months_stay_exactly_as_they_are(): void
    {
        $this->october();
        $this->writeBaseline();
        $sept = PayrollRun::query()->create(['period' => '2026-09', 'status' => 'draft', 'currency' => 'USD']);
        $november = $this->makeAssignment($this->makeEmployee(), $this->company, null, 90, '2026-11-03');
        $septLines = PayrollItem::query()->where('period', '2026-09')->count();

        $this->commit();

        $this->assertSame('draft', $sept->fresh()->status);
        $this->assertSame($septLines, PayrollItem::query()->where('period', '2026-09')->count());
        $this->assertSame('active', $november->fresh()->status->value);
        $this->assertSame('2026-11-03', $november->fresh()->start_date->toDateString());
        $this->assertSame(1, PayrollRun::query()->where('period', '2026-09')->count());
    }

    public function test_a_baseline_that_does_not_start_on_the_first_day_of_the_month_is_blocked(): void
    {
        $this->october();
        $this->writeBaseline(start: '2026-10-05');

        $this->assertContains('BASELINE_START_NOT_FIRST_DAY', array_column($this->plan()['blockers'], 'code'));
        $this->commit(['--confirm' => 'whatever'], 1);
        $this->assertSame('ended', $this->a['mohamed']->fresh()->status->value);
    }

    public function test_a_change_that_would_reach_another_month_is_blocked_not_widened(): void
    {
        $this->october();
        $this->writeBaseline();
        // A baseline assignment ended in November, and a non-baseline row that started in September.
        $this->a['ibrahim']->update(['end_date' => '2026-11-10', 'status' => 'ended']);
        $this->a['kept']->update(['start_date' => '2026-09-20']);
        $oddRow = $this->makeAssignment($this->makeEmployee(), $this->company, null, 80, '2026-09-15');

        $codes = array_column($this->plan(['keep' => []])['blockers'], 'code');

        $this->assertContains('END_OUTSIDE_MONTH', $codes);
        $this->assertContains('START_OUTSIDE_MONTH', $codes);
        $this->commit(['--confirm' => 'x'], 1);
        $this->assertSame('active', $oddRow->fresh()->status->value);
    }

    public function test_a_row_that_depends_on_a_retired_assignment_outside_the_month_blocks_the_restart(): void
    {
        $this->october();
        $this->writeBaseline();
        $later = $this->makeAssignment($this->makeEmployee(), $this->company, null, 90, '2026-11-02');
        $later->update(['replaces_assignment_id' => $this->a['adan']->id]);

        $this->assertContains('DEPENDS_ON_RETIRED', array_column($this->plan()['blockers'], 'code'));
    }

    // ------------------------------------------------------------------ the baseline is Excel, never inferred

    public function test_a_baseline_row_without_an_assignment_is_a_blocker_and_nothing_is_created(): void
    {
        $this->october();
        $this->writeBaseline();
        file_put_contents($this->csv, '99999,'.$this->makeEmployee()->id.",EMP-999999,x,active,\"{$this->company->name}\",120,2026-10-01\n", FILE_APPEND);
        $count = EmployeeWorkAssignment::query()->count();

        $this->assertContains('BASELINE_ASSIGNMENT_MISSING', array_column($this->plan()['blockers'], 'code'));
        $this->restart([], 1);
        $this->assertSame($count, EmployeeWorkAssignment::query()->count());
    }

    public function test_a_salary_that_differs_from_the_excel_is_only_reported_and_never_touched(): void
    {
        $this->october();
        $this->writeBaseline();
        // The assignment #95 case: the owner decided to keep the raise effective 31 October.
        $row = $this->a['ibrahim'];
        $row->update(['salary_amount' => 160]);
        $row->salaryHistories()->create(['effective_from' => '2026-10-31', 'amount' => 160, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'raise']);
        $histories = DB::table('assignment_salary_histories')->where('work_assignment_id', $row->id)->orderBy('id')->get()->toJson();

        $plan = $this->plan();
        $this->assertSame(1, $plan['summary']['salary_differs']);
        $this->assertArrayNotHasKey('salary_move', $plan['summary']);

        $this->commit();

        $this->assertSame($histories, DB::table('assignment_salary_histories')->where('work_assignment_id', $row->id)->orderBy('id')->get()->toJson(), 'salary history is byte-identical');
        $this->assertSame('160.00', (string) $row->fresh()->salary_amount);
        $this->assertSame(['2026-10-01', '2026-10-31'], $row->salaryHistories()->orderBy('effective_from')->get()->map(fn ($h) => $h->effective_from->toDateString())->all(), 'the raise stays effective 31 October');
    }

    public function test_a_plan_that_differs_from_the_approved_one_is_blocked_and_reported(): void
    {
        $this->october();
        $this->writeBaseline();
        $approved = ['--expect-reopen' => $this->a['mohamed']->id.','.$this->a['zakariye']->id, '--expect-retire' => implode(',', array_map(fn (string $k): int => $this->a[$k]->id, ['adan', 'mohamed_back', 'suudi', 'zakariye_back'])), '--expect-keep' => (string) $this->a['kept']->id];

        // Exactly the approved plan: fine.
        $this->assertSame([], $this->plan()['blockers']);
        $this->restart($approved, 0);

        // Something unapproved appears (a new assignment entered after the review overlapping the month): the plan now retires one more row -> blocked.
        $extra = $this->makeAssignment($this->makeEmployee(), $this->company, null, 90, '2026-10-15');
        $this->restart($approved, 1);
        $this->commit($approved + ['--confirm' => $this->hash()], 1);
        $this->assertSame('active', $extra->fresh()->status->value, 'nothing was applied');
        $this->assertSame('ended', $this->a['mohamed']->fresh()->status->value);

        // A wrong expectation is blocked even when the data has not changed.
        $extra->update(['status' => 'cancelled']);
        $this->restart(['--expect-reopen' => (string) $this->a['mohamed']->id], 1);
    }

    public function test_attached_covers_or_absences_block_the_restart(): void
    {
        $this->october();
        $this->writeBaseline();
        AssignmentAbsence::query()->create(['work_assignment_id' => $this->a['mohamed']->id, 'last_working_day' => '2026-10-11', 'absent_from' => '2026-10-12']);

        $this->assertContains('COVERS_OR_ABSENCES_PRESENT', array_column($this->plan()['blockers'], 'code'));
    }

    // ------------------------------------------------------------------ authority and confirmation

    public function test_only_a_super_admin_with_a_reason_and_the_reviewed_plan_hash_can_apply_it(): void
    {
        $this->october();
        $this->writeBaseline();
        $hr = Admin::factory()->create(['role' => 'hr_manager']);
        $before = $this->fingerprint();

        $this->commit(['--admin-email' => $hr->email], 1);
        $this->commit(['--admin-email' => 'nobody@example.test'], 1);
        $this->commit(['--reason' => 'short'], 1);
        $this->commit(['--confirm' => 'not-the-hash'], 1);
        $this->commit(['--confirm' => ''], 1);

        $this->assertSame($before, $this->fingerprint());
    }

    public function test_a_change_after_the_dry_run_invalidates_the_confirmation(): void
    {
        $this->october();
        $this->writeBaseline();
        $hash = $this->hash();

        $this->a['ibrahim']->update(['end_date' => '2026-10-20', 'status' => 'ended']);

        $this->commit(['--confirm' => $hash], 1);
        $this->assertSame('ended', $this->a['ibrahim']->fresh()->status->value, 'nothing was applied');
        $this->assertSame('ended', $this->a['mohamed']->fresh()->status->value);
    }

    public function test_a_result_that_fails_verification_is_rolled_back_completely(): void
    {
        $this->october();
        $this->writeBaseline();
        // The owner-confirmed entry belongs to the same employee and company as a baseline assignment: two open assignments overlap = double pay.
        $this->a['kept']->update(['employee_id' => $this->a['ibrahim']->employee_id]);
        $before = $this->fingerprint();

        $plan = $this->plan();
        $dry = app(MonthBaselineRestorer::class)->dryRun($plan);
        $this->assertFalse($dry['verification']['ok']);
        $this->assertStringContainsString('Overlapping assignments', implode(' ', $dry['verification']['problems']));

        $this->commit(['--confirm' => $plan['plan_hash']], 1);
        $this->assertSame($before, $this->fingerprint());
    }

    // ------------------------------------------------------------------ afterwards the owner enters October again

    public function test_after_the_restart_the_owner_can_enter_the_real_october_changes_and_payroll_pays_each_day_once(): void
    {
        $this->october();
        $this->writeBaseline();
        $this->commit();
        $mohamed = $this->a['mohamed']->fresh();
        $stand = $this->makeEmployee();

        // The owner's real October: last working day 11 October, a Replacement from 12 October, the holder back on 13 October.
        $url = "/api/v1/admin/hr/client-companies/{$this->company->id}/employees";
        $this->app['auth']->forgetGuards();
        $replaced = $this->withToken($this->token)->postJson("{$url}/{$mohamed->id}/replace", ['replacement_employee_id' => $stand->id, 'replacement_date' => '2026-10-12', 'original_last_day' => '2026-10-11', 'assignment_type' => 'replacement', 'position_id' => $this->cleaner->id])->assertOk();
        $this->app['auth']->forgetGuards();
        $returned = $this->withToken($this->token)->postJson("{$url}/{$replaced->json('data.replacement.id')}/return", ['return_date' => '2026-10-13', 'salary_amount' => 140, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'])->assertOk();

        $this->assertSame($mohamed->id, $returned->json('data.returned.id'), 'the baseline assignment keeps its id through Replacement and return');

        $this->generateDraft();
        $lines = PayrollItem::query()->where('period', '2026-10')->get()->keyBy('work_assignment_id');
        $this->assertSame(13548 + 452, $lines[$mohamed->id]->gross_cents + $lines[$replaced->json('data.replacement.id')]->gross_cents, '30 days + 1 day of 140 = exactly 140.00');
        $this->assertSame(53000, (int) $lines->sum('gross_cents'));
        $this->assertSame('draft', PayrollRun::query()->where('period', '2026-10')->value('status'));
    }

    public function test_the_service_refuses_a_malformed_month(): void
    {
        $this->expectException(\RuntimeException::class);
        app(MonthBaselineRestorer::class)->plan('October 2026', []);
    }

    public function test_a_cancelled_baseline_assignment_is_never_reopened_by_this_tool(): void
    {
        $this->october();
        $this->writeBaseline();
        $this->a['ibrahim']->update(['status' => WorkAssignmentStatus::Cancelled]);

        $this->assertContains('BASELINE_ASSIGNMENT_CANCELLED', array_column($this->plan()['blockers'], 'code'));
        $this->assertSame(0, TemporaryReplacement::query()->count());
    }
}
