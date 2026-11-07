<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssignmentAbsence;
use App\Models\AssignmentCycleEvent;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\Position;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FinalizesCompanies;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision F-A (2026-11-07): a Replacement must end no later than the day BEFORE the original holder's return date. Holder returns 20 October: the
 * latest last day is 19 October; 20 October or later is refused (422 REPLACEMENT_RUNS_PAST_HOLDER_RETURN), the dates are never shortened for the user, and
 * a refusal writes nothing. Enforced on the server for every role and every entry point that can set a Replacement's last day (date edit / extension,
 * Recovery Center, company reopen); Return itself ends the Replacement on exactly that latest day. Records that already break the rule are never rewritten.
 * Synthetic data only, everything through the API unless a test says it builds an "older" record directly.
 */
class ReplacementEndsBeforeHolderReturnTest extends TestCase
{
    use FinalizesCompanies;
    use HrFixtures;
    use RefreshDatabase;

    private const CODE = 'REPLACEMENT_RUNS_PAST_HOLDER_RETURN';

    private ClientCompany $company;

    private Position $cleaner;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
        $this->company = $this->makeCompany('Return Rule Co');
        $this->cleaner = Position::query()->create(['name' => 'Cleaner R']);
        [, $this->admin] = $this->superAdminToken();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function url(string $tail = 'employees'): string
    {
        return "/api/v1/admin/hr/client-companies/{$this->company->id}/{$tail}";
    }

    /** The original Permanent assignment: $310 a month, so one calendar day of October is exactly $10. */
    private function original(string $start = '2026-10-01'): EmployeeWorkAssignment
    {
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->company, null, 310, $start);
        $assignment->update(['position_id' => $this->cleaner->id]);

        return $assignment;
    }

    private function replace(EmployeeWorkAssignment $original, string $lastDay = '2026-10-11', string $from = '2026-10-12', ?Employee $who = null): TestResponse
    {
        return $this->as($this->admin)->postJson($this->url()."/{$original->id}/replace", [
            'replacement_employee_id' => ($who ?? $this->makeEmployee())->id, 'replacement_date' => $from, 'original_last_day' => $lastDay,
            'assignment_type' => 'replacement', 'position_id' => $this->cleaner->id,
        ]);
    }

    private function giveBack(int $replacementId, string $returnDate): TestResponse
    {
        return $this->as($this->admin)->postJson($this->url()."/{$replacementId}/return", ['return_date' => $returnDate, 'salary_amount' => 310, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly']);
    }

    private function edit(int $assignmentId, array $payload, ?string $token = null): TestResponse
    {
        return $this->as($token ?? $this->admin)->putJson("/api/v1/admin/hr/work-assignments/{$assignmentId}", $payload);
    }

    /** A finished cycle: the holder was last at work on 11 October, the Replacement worked from 12 October, the holder returned on 20 October. */
    private function finishedCycle(): array
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $this->giveBack($replacementId, '2026-10-20')->assertOk();

        return [$original, EmployeeWorkAssignment::query()->findOrFail($replacementId), AssignmentAbsence::query()->where('work_assignment_id', $original->id)->sole()];
    }

    /** @return array<string, mixed> everything a refusal must leave exactly as it was */
    private function state(): array
    {
        return [
            'assignments' => EmployeeWorkAssignment::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'employees' => Employee::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'absences' => AssignmentAbsence::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'cycle_events' => AssignmentCycleEvent::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'audit' => AuditLog::query()->count(),
            'payroll_items' => PayrollItem::query()->orderBy('id')->get()->map->getAttributes()->all(),
        ];
    }

    private function hrManager(): string
    {
        $admin = Admin::factory()->create(['role' => AdminRole::HrManager]);

        foreach (['hr.view', 'hr.manage', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve'] as $key) {
            DB::table('admin_permissions')->insert(['admin_id' => $admin->id, 'permission_id' => Permission::query()->where('key', $key)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $admin->createToken('t')->plainTextToken;
    }

    /** An "older" Replacement written directly: it ran past the return before the rule existed (the API refuses to create one now). */
    private function legacyReplacementPastReturn(EmployeeWorkAssignment $original, AssignmentAbsence $absence, string $end): EmployeeWorkAssignment
    {
        $row = EmployeeWorkAssignment::query()->create([
            'employee_id' => $this->makeEmployee()->id, 'client_company_id' => $this->company->id, 'replaces_assignment_id' => $original->id, 'position_id' => $this->cleaner->id,
            'assignment_type' => 'replacement', 'start_date' => '2026-10-12', 'end_date' => $end, 'salary_amount' => 310, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly', 'status' => 'ended',
        ]);
        $row->salaryHistories()->create(['effective_from' => '2026-10-12', 'amount' => 310, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'Test fixture']);
        AssignmentCycleEvent::query()->create(['absence_id' => $absence->id, 'work_assignment_id' => $original->id, 'event_type' => AssignmentCycleEvent::REPLACEMENT_STARTED, 'event_date' => '2026-10-12', 'replacement_assignment_id' => $row->id]);

        return $row;
    }

    // ------------------------------------------------------------------------------------------------ Return and date edits

    public function test_return_ends_the_replacement_on_the_day_before_the_return_which_is_the_latest_allowed_day(): void
    {
        [, $replacement, $absence] = $this->finishedCycle();

        $this->assertSame('2026-10-20', $absence->returned_on->toDateString());
        $this->assertSame('2026-10-19', $replacement->end_date->toDateString(), 'ending the day before the holder returns is exactly what Return does');
        $this->assertSame('ended', $replacement->status->value);
    }

    public function test_a_date_edit_may_end_the_replacement_on_the_day_before_the_return_but_not_on_or_after_it_and_a_refusal_writes_nothing(): void
    {
        [, $replacement] = $this->finishedCycle();

        // Shorter, then back to the latest allowed day: both fine.
        $this->edit($replacement->id, ['end_date' => '2026-10-18'])->assertOk();
        $this->assertSame('2026-10-18', $replacement->fresh()->end_date->toDateString());
        $this->edit($replacement->id, ['end_date' => '2026-10-19'])->assertOk();
        $this->assertSame('2026-10-19', $replacement->fresh()->end_date->toDateString(), 'the day before the return is allowed');

        $cases = [
            'on the return date' => '2026-10-20',
            'the day after the return' => '2026-10-21',
            'a month later' => '2026-11-20',
            'far in the future' => '2027-01-01',
        ];

        foreach ($cases as $name => $end) {
            $before = $this->state();
            $response = $this->edit($replacement->id, ['end_date' => $end])->assertStatus(422);

            $this->assertSame(self::CODE, $response->json('error_code'), $name);
            $this->assertSame(['2026-10-20'], $response->json('errors.holder_return_date'), $name);
            $this->assertSame(['2026-10-19'], $response->json('errors.latest_end_date'), $name);
            $this->assertSame([$end], $response->json('errors.requested_end_date'), $name);
            $this->assertNotEmpty($response->json('errors.end_date.0'), $name);
            $this->assertStringContainsString('2026-10-20', $response->json('message'), $name);
            $this->assertStringContainsString('Nothing was changed', $response->json('message'), $name);
            $this->assertSame($before, $this->state(), "{$name}: nothing was written and the date was not shortened for the user");
            $this->assertSame('2026-10-19', $replacement->fresh()->end_date->toDateString(), $name);
        }
    }

    public function test_the_rule_is_enforced_for_an_hr_manager_too_so_it_cannot_be_bypassed_by_the_api(): void
    {
        [, $replacement] = $this->finishedCycle();
        $hr = $this->hrManager();

        $before = $this->state();
        $this->edit($replacement->id, ['end_date' => '2026-10-20'], $hr)->assertStatus(422)->assertJsonPath('error_code', self::CODE);
        $this->edit($replacement->id, ['end_date' => '2026-12-31'], $hr)->assertStatus(422)->assertJsonPath('error_code', self::CODE);
        $this->assertSame($before, $this->state());

        $this->edit($replacement->id, ['end_date' => '2026-10-18'], $hr)->assertOk();
    }

    public function test_a_replacement_with_no_return_to_wait_for_has_no_such_limit(): void
    {
        // An older Replacement with no cycle record at all (nothing says anyone returns): not limited by this rule.
        $original = $this->original();
        $old = EmployeeWorkAssignment::query()->create([
            'employee_id' => $this->makeEmployee()->id, 'client_company_id' => $this->company->id, 'replaces_assignment_id' => $original->id, 'position_id' => $this->cleaner->id,
            'assignment_type' => 'replacement', 'start_date' => '2026-10-12', 'end_date' => '2026-10-15', 'salary_amount' => 310, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly', 'status' => 'ended',
        ]);
        $original->update(['end_date' => '2026-10-11', 'status' => 'ended']);

        $this->edit($old->id, ['end_date' => '2026-10-28'])->assertOk();
    }

    public function test_a_replacement_that_already_ran_past_the_return_is_left_alone_and_can_only_be_repaired(): void
    {
        [$original, $genuine, $absence] = $this->finishedCycle();
        $genuine->update(['status' => 'cancelled']); // the older record is the only Replacement of the position, so nothing else shares its days
        $legacy = $this->legacyReplacementPastReturn($original, $absence, '2026-10-25');
        $before = $legacy->fresh()->getAttributes();

        // Not rewritten by anything but an explicit edit: a notes-only edit changes no date and is allowed; the dates stay as they were.
        $this->edit($legacy->id, ['notes' => 'Checked by HR'])->assertOk();
        $this->assertSame($before['end_date'], $legacy->fresh()->getAttributes()['end_date']);
        $this->assertSame('Checked by HR', $legacy->fresh()->notes);

        // A real change of dates is judged in full: keeping or widening the overrun is refused, moving to a compliant day repairs it.
        $this->edit($legacy->id, ['end_date' => '2026-10-26'])->assertStatus(422)->assertJsonPath('error_code', self::CODE);
        $this->edit($legacy->id, ['end_date' => '2026-10-20'])->assertStatus(422)->assertJsonPath('error_code', self::CODE);
        $this->assertSame('2026-10-25', $legacy->fresh()->end_date->toDateString());
        $this->edit($legacy->id, ['end_date' => '2026-10-19'])->assertOk();
        $this->assertSame('2026-10-19', $legacy->fresh()->end_date->toDateString());
    }

    // ------------------------------------------------------------------------------------------------ Recovery Center

    public function test_the_recovery_center_blocks_a_date_correction_past_the_return_and_an_absence_correction_that_would_strand_a_replacement(): void
    {
        [$original, $replacement, $absence] = $this->finishedCycle();

        $preview = fn (string $workflow, array $subject, array $params) => $this->as($this->admin)->postJson('/api/v1/admin/recovery/preview', ['workflow' => $workflow, ...$subject, 'params' => $params])->assertOk()->json('data');

        $past = $preview('correct_assignment_dates', ['assignment_id' => $replacement->id], ['month' => '2026-10', 'end_date' => '2026-10-25']);
        $this->assertFalse($past['can_apply']);
        $this->assertContains(self::CODE, array_column($past['blockers'], 'code'));

        $ok = $preview('correct_assignment_dates', ['assignment_id' => $replacement->id], ['month' => '2026-10', 'end_date' => '2026-10-18']);
        $this->assertNotContains(self::CODE, array_column($ok['blockers'], 'code'), 'a day before the return is not blocked by this rule');

        // Moving the return EARLIER is fine for the Replacement that ends at the return (it follows)...
        $moved = $preview('correct_absence', ['assignment_id' => $original->id], ['month' => '2026-10', 'absence_id' => $absence->id, 'returned_on' => '2026-10-17']);
        $this->assertNotContains(self::CODE, array_column($moved['blockers'], 'code'));

        // ...but not while another Replacement of the same absence (an older record) would then last past the new return.
        $this->legacyReplacementPastReturn($original, $absence, '2026-10-19');
        $stranded = $preview('correct_absence', ['assignment_id' => $original->id], ['month' => '2026-10', 'absence_id' => $absence->id, 'returned_on' => '2026-10-17']);
        $this->assertFalse($stranded['can_apply']);
        $this->assertContains(self::CODE, array_column($stranded['blockers'], 'code'));
    }

    // ------------------------------------------------------------------------------------------------ company reopen

    public function test_reopening_a_company_does_not_restore_a_replacement_past_its_holders_return(): void
    {
        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');
        $absence = AssignmentAbsence::query()->where('work_assignment_id', $original->id)->sole();

        // The holder is recorded as back on 20 October while the Replacement is still open (an older, inconsistent record).
        $absence->update(['returned_on' => '2026-10-20']);

        $this->as($this->admin)->postJson($this->url('close'), ['reason' => 'Closed for the test', 'effective_date' => '2026-10-15'])->assertCreated();
        $this->assertSame('2026-10-15', EmployeeWorkAssignment::query()->findOrFail($replacementId)->end_date->toDateString());

        $row = collect($this->as($this->admin)->getJson($this->url('closure'))->assertOk()->json('data.assignments'))->firstWhere('assignment_id', $replacementId);
        $this->assertFalse($row['restorable']);
        $this->assertStringContainsString('must end no later than the day before the original employee returns', $row['conflict']);
        $this->assertStringContainsString('2026-10-19', $row['conflict']);
    }

    // ------------------------------------------------------------------------------------------------ payroll

    /** @return array<int, PayrollItem> work_assignment_id => item */
    private function october(): array
    {
        $this->as($this->admin)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertSuccessful();

        return PayrollItem::query()->where('period', '2026-10')->get()->keyBy('work_assignment_id')->all();
    }

    public function test_payroll_pays_each_day_of_a_returned_cycle_once_and_a_refused_extension_cannot_change_it(): void
    {
        [$original, $replacement] = $this->finishedCycle();

        $this->edit($replacement->id, ['end_date' => '2026-10-31'])->assertStatus(422)->assertJsonPath('error_code', self::CODE);

        $items = $this->october();

        $this->assertSame(11 + 12, (int) $items[$original->id]->eligible_days, 'Oct 1-11 and Oct 20-31');
        $this->assertSame(8, (int) $items[$replacement->id]->eligible_days, 'Oct 12-19');
        $this->assertSame(31000, (int) $items[$original->id]->gross_cents + (int) $items[$replacement->id]->gross_cents, 'the position costs exactly one month: no day twice, none lost');
    }

    public function test_the_payroll_of_an_older_replacement_past_the_return_takes_the_days_from_the_returned_holder_and_does_not_pay_them_twice(): void
    {
        [$original, $replacement, $absence] = $this->finishedCycle();
        $this->edit($replacement->id, ['end_date' => '2026-10-18'])->assertOk();
        $legacy = $this->legacyReplacementPastReturn($original, $absence, '2026-10-31');
        $replacement->update(['status' => 'cancelled']);

        $items = $this->october();

        // This is the harm the rule prevents: the Replacement keeps the days after the holder came back, so the holder is paid for fewer days than worked.
        $this->assertSame(11, (int) $items[$original->id]->eligible_days, 'the holder loses Oct 20-31 although they are back at work');
        $this->assertSame(20, (int) $items[$legacy->id]->eligible_days, 'Oct 12-31');
        $this->assertSame(31000, (int) $items[$original->id]->gross_cents + (int) $items[$legacy->id]->gross_cents, 'a month is still paid once: misallocated, not duplicated');
    }

    // ------------------------------------------------------------------------------------------------ owner rule A7: a day of a position is paid once

    /** @return array{0: EmployeeWorkAssignment, 1: int, 2: int} the holder, and the ids of two Replacements of one absence: #1 12-15 Oct, #2 from 16 Oct (swapped in) */
    private function swappedCycle(): array
    {
        $original = $this->original();
        $first = $this->replace($original)->assertOk()->json('data.replacement.id');
        $second = $this->as($this->admin)->postJson($this->url()."/{$first}/replace", ['replacement_employee_id' => $this->makeEmployee()->id, 'replacement_date' => '2026-10-16', 'assignment_type' => 'replacement'])->assertOk()->json('data.replacement.id');

        return [$original, $first, $second];
    }

    public function test_two_replacements_of_one_position_cannot_be_given_the_same_day_by_a_date_edit_and_a_refusal_writes_nothing(): void
    {
        [, $first, $second] = $this->swappedCycle();

        // Extending the swapped-out Replacement into its successor's days would pay both for 16-18 October.
        $before = $this->state();
        $response = $this->edit($first, ['end_date' => '2026-10-18'])->assertStatus(422);
        $this->assertSame('REPLACEMENT_OVERLAPS_REPLACEMENT', $response->json('error_code'));
        $this->assertSame(['replacement', $second, '2026-10-16', null], [$response->json('errors.conflicts.0.kind'), $response->json('errors.conflicts.0.id'), $response->json('errors.conflicts.0.start_date'), $response->json('errors.conflicts.0.end_date')]);
        $this->assertStringContainsString("Replacement #{$second}", $response->json('message'));
        $this->assertSame($before, $this->state(), 'nothing was written');

        // The inclusive boundary: the day before the successor starts is free, and shortening is always fine.
        $this->edit($first, ['end_date' => '2026-10-14'])->assertOk();
        $this->edit($first, ['end_date' => '2026-10-15'])->assertOk();
        $this->assertSame('2026-10-15', EmployeeWorkAssignment::query()->findOrFail($first)->end_date->toDateString());
    }

    public function test_a_swapped_out_replacement_cannot_be_reopened_next_to_its_successor_and_payroll_pays_every_day_once(): void
    {
        [$original, $first, $second] = $this->swappedCycle();

        $before = $this->state();
        $this->as($this->admin)->postJson($this->url()."/{$first}/reopen", ['reason' => 'Reopened by mistake next to the successor'])
            ->assertStatus(422)->assertJsonPath('error_code', 'REPLACEMENT_OVERLAPS_REPLACEMENT')->assertJsonPath('errors.conflicts.0.id', $second);
        $this->assertSame($before, $this->state());
        $this->assertSame('ended', EmployeeWorkAssignment::query()->findOrFail($first)->status->value);

        $items = $this->october();
        $this->assertSame([11, 4, 16], [(int) $items[$original->id]->eligible_days, (int) $items[$first]->eligible_days, (int) $items[$second]->eligible_days]);
        $this->assertSame(31000, (int) $items[$original->id]->gross_cents + (int) $items[$first]->gross_cents + (int) $items[$second]->gross_cents, 'the position costs exactly one month: no day paid twice');
    }

    public function test_the_recovery_center_blocks_a_date_correction_into_the_days_of_another_replacement_of_the_position(): void
    {
        [, $first] = $this->swappedCycle();

        $preview = fn (array $params) => $this->as($this->admin)->postJson('/api/v1/admin/recovery/preview', ['workflow' => 'correct_assignment_dates', 'assignment_id' => $first, 'params' => $params])->assertOk()->json('data');

        $overlap = $preview(['month' => '2026-10', 'end_date' => '2026-10-18']);
        $this->assertFalse($overlap['can_apply']);
        $this->assertContains('REPLACEMENT_OVERLAPS_REPLACEMENT', array_column($overlap['blockers'], 'code'));

        $clear = $preview(['month' => '2026-10', 'end_date' => '2026-10-14']);
        $this->assertNotContains('REPLACEMENT_OVERLAPS_REPLACEMENT', array_column($clear['blockers'], 'code'));
    }

    public function test_reopening_a_company_never_restores_two_replacements_of_one_position_for_the_same_days(): void
    {
        $original = $this->original();
        $this->replace($original)->assertOk();
        $second = EmployeeWorkAssignment::query()->create([
            'employee_id' => $this->makeEmployee()->id, 'client_company_id' => $this->company->id, 'replaces_assignment_id' => $original->id, 'position_id' => $this->cleaner->id,
            'assignment_type' => 'replacement', 'start_date' => '2026-10-16', 'salary_amount' => 310, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly', 'status' => 'active',
        ]);
        $second->salaryHistories()->create(['effective_from' => '2026-10-16', 'amount' => 310, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'Test fixture']);
        $first = EmployeeWorkAssignment::query()->where('replaces_assignment_id', $original->id)->where('id', '!=', $second->id)->where('assignment_type', 'replacement')->firstOrFail();
        // An older record: the first Replacement is still open next to the second (this is how the data looked before the rule).
        $this->assertNull($first->end_date);

        $this->as($this->admin)->postJson($this->url('close'), ['reason' => 'Closed for the test', 'effective_date' => '2026-10-20'])->assertCreated();
        $before = $this->state();

        $rows = collect($this->as($this->admin)->getJson($this->url('closure'))->assertOk()->json('data.assignments'));
        $this->assertTrue((bool) $rows->firstWhere('assignment_id', $first->id)['restorable'], 'each one alone could come back');

        $this->as($this->admin)->postJson($this->url('reopen'), ['reason' => 'Reopened for the test', 'restore_assignment_ids' => [$first->id, $second->id]])
            ->assertStatus(422)->assertJsonPath('error_code', 'RESTORE_CONFLICT');
        $this->assertSame($before, $this->state(), 'the whole reopening is refused and nothing is restored');
    }

    // ------------------------------------------------------------------------------------------------ locking (PostgreSQL only)

    public function test_return_locks_the_replacement_row_before_the_holder_row_and_a_reopen_locks_before_it_judges_the_siblings(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row locks are only visible on PostgreSQL; the real two-process race is run against the local PG server (see docs/PRE_PRODUCTION_FULL_WEB_PANEL_QA.md).');
        }

        $original = $this->original();
        $replacementId = $this->replace($original)->assertOk()->json('data.replacement.id');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->giveBack($replacementId, '2026-10-20')->assertOk();
        $locks = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains(strtolower($q['query']), 'from "employee_work_assignments"') && str_contains(strtolower($q['query']), 'for update'));
        $lockOf = fn (int $id): int|false => $locks->search(fn (array $q): bool => in_array($id, array_map('intval', $q['bindings']), true));
        $this->assertNotFalse($lockOf($replacementId), 'the Replacement row is locked');
        $this->assertNotFalse($lockOf($original->id), 'the holder row is locked');
        $this->assertLessThan($lockOf($original->id), $lockOf($replacementId), 'Replacement first, holder second: the same order every Replacement writer uses, so Return cannot deadlock with a date edit');

        // Reopen (A7): the position's rows are locked before the sibling Replacements are looked at.
        [, $first, $second] = $this->swappedCycle();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->as($this->admin)->postJson($this->url()."/{$first}/reopen", ['reason' => 'Reopened by mistake next to the successor'])->assertStatus(422)->assertJsonPath('error_code', 'REPLACEMENT_OVERLAPS_REPLACEMENT');
        $log = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower($q));
        $lock = $log->search(fn ($q) => str_contains($q, 'from "employee_work_assignments"') && str_contains($q, 'for update'));
        $judge = $log->search(fn ($q) => str_contains($q, 'from "employee_work_assignments"') && str_contains($q, '"replaces_assignment_id" = ?') && str_contains($q, '"id" != ?') && ! str_contains($q, 'for update'));
        $this->assertNotFalse($lock);
        $this->assertNotFalse($judge, 'the sibling Replacements are judged');
        $this->assertLessThan($judge, $lock, 'the lock comes first, so a concurrent writer waits and then sees the other one');
        $this->assertNotNull($second);
    }

    public function test_a_finalized_payroll_month_is_never_silently_rewritten_by_these_edits(): void
    {
        [$original, $replacement] = $this->finishedCycle();
        $run = $this->as($this->admin)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertSuccessful()->json('data.id');
        $this->finalizeEveryCompany($run, $this->admin);
        $this->assertSame('finalized', PayrollRun::query()->findOrFail($run)->status);
        $frozen = $this->state();

        // Refused by F-A: nothing at all moves.
        $this->edit($replacement->id, ['end_date' => '2026-10-31'])->assertStatus(422)->assertJsonPath('error_code', self::CODE);
        $this->assertSame($frozen, $this->state());

        // A permitted edit that would change a finalized month is not applied silently either: it is a recorded correction that needs a reason.
        $this->edit($replacement->id, ['end_date' => '2026-10-18'])->assertStatus(422);
        $this->assertSame($frozen, $this->state(), 'the finalized lines, assignments and audit trail are exactly as they were');
        $this->assertSame(31000, (int) PayrollItem::query()->where('work_assignment_id', $original->id)->sole()->gross_cents + (int) PayrollItem::query()->where('work_assignment_id', $replacement->id)->sole()->gross_cents);
    }
}
