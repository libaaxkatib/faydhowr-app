<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\AssignmentAbsence;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRunSection;
use App\Models\PayrollWorkplacePayment;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizesCompanies;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The freshness guard for SOURCE data (owner approval of 2026-11-05): an assignment, a salary row or a Temporary cover changed after a company was generated
 * makes its draft stale. Review and Finalize refuse it (409 PAYROLL_SOURCES_CHANGED), name every change, and ask for Generate again; a draft whose data did
 * not change, a finalized company, a section generated before the guard existed and anything that does not touch the company's pay are never blocked.
 * Synthetic data only.
 */
class PayrollSourceFreshnessGuardTest extends TestCase
{
    use FinalizesCompanies;
    use HrFixtures;
    use RefreshDatabase;

    private const P = '2026-10';

    private ClientCompany $alpha;

    private ClientCompany $bravo;

    private Employee $ann;

    private Employee $bob;

    private Employee $cleo;

    private EmployeeWorkAssignment $annAssignment;

    private EmployeeWorkAssignment $bobAssignment;

    private EmployeeWorkAssignment $cleoAssignment;

    private string $super;

    private int $superId;

    private string $hr;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
        [$admin, $this->super] = $this->superAdminToken();
        $this->superId = $admin->id; // rows that name an admin (created_by) need a real one: a foreign key on PostgreSQL
        $this->hr = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve']);

        $this->alpha = $this->makeCompany('Alpha Co');
        $this->bravo = $this->makeCompany('Bravo Co');
        $this->ann = $this->makeEmployee();
        $this->bob = $this->makeEmployee();
        $this->cleo = $this->makeEmployee();
        $this->annAssignment = $this->makeAssignment($this->ann, $this->alpha, null, 300, '2026-01-01');
        $this->bobAssignment = $this->makeAssignment($this->bob, $this->alpha, null, 200, '2026-01-01');
        $this->cleoAssignment = $this->makeAssignment($this->cleo, $this->bravo, null, 400, '2026-01-01');
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

    private function generate(ClientCompany $c): void
    {
        $this->as($this->super)->json('POST', '/api/v1/admin/hr/payroll/'.self::P."/companies/{$c->id}/generate")->assertCreated();
    }

    private function section(ClientCompany $c): PayrollRunSection
    {
        return PayrollRunSection::query()->whereHas('run', fn ($q) => $q->where('period', self::P))->where('client_company_id', $c->id)->firstOrFail();
    }

    private function review(ClientCompany $c, ?string $token = null)
    {
        return $this->as($token ?? $this->hr)->patchJson('/api/v1/admin/hr/payroll-run-sections/'.$this->section($c)->id.'/review');
    }

    private function finalize(ClientCompany $c, ?string $token = null)
    {
        return $this->as($token ?? $this->hr)->patchJson('/api/v1/admin/hr/payroll-run-sections/'.$this->section($c)->id.'/finalize');
    }

    private function warnings(ClientCompany $c)
    {
        return $this->as($this->hr)->getJson('/api/v1/admin/hr/payroll/'.self::P."/companies/{$c->id}/warnings")->assertOk();
    }

    /** Asserts the draft is refused as stale and returns the listed changes. @return list<array<string, mixed>> */
    private function assertStale(ClientCompany $c, string $category, string $change): array
    {
        $response = $this->review($c)->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_SOURCES_CHANGED');
        $changes = $response->json('errors.changes');
        $this->assertContains([$category, $change], array_map(fn (array $x): array => [$x['category'], $x['change']], $changes), 'the answer names the change');
        $this->assertSame('draft', $this->section($c)->status, 'nothing was changed by the refusal');
        $this->warnings($c)->assertJsonPath('data.source_inputs_changed', true)->assertJsonPath('data.source_changes_total', count($changes));

        return $changes;
    }

    private function assertFresh(ClientCompany $c): void
    {
        $this->warnings($c)->assertJsonPath('data.source_inputs_changed', false)->assertJsonPath('data.source_changes_total', 0);
    }

    // ------------------------------------------------------------------------------------------------ unchanged data

    public function test_a_draft_whose_source_data_did_not_change_is_reviewed_and_finalized_normally(): void
    {
        $this->generate($this->alpha);
        $this->assertNotNull($this->section($this->alpha)->generation_inputs, 'Generate stores the snapshot');
        $this->assertFresh($this->alpha);

        $this->review($this->alpha)->assertOk();
        $this->finalize($this->alpha)->assertOk();
        $this->assertSame('finalized', $this->section($this->alpha)->status);
    }

    public function test_generating_again_after_a_change_makes_the_draft_fresh_again(): void
    {
        $this->generate($this->alpha);
        $this->annAssignment->forceFill(['end_date' => '2026-10-20'])->save();
        $this->assertStale($this->alpha, 'assignment', 'changed');

        $this->generate($this->alpha);
        $this->assertFresh($this->alpha);
        $this->review($this->alpha)->assertOk();
        $this->assertSame(20, (int) PayrollItem::query()->where('work_assignment_id', $this->annAssignment->id)->value('eligible_days'), 'the new line uses the changed end date');
    }

    // ------------------------------------------------------------------------------------------------ assignment changes

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function assignmentChanges(): array
    {
        return [
            'the end date' => ['end', 'changed', 'end'],
            'the start date' => ['start', 'changed', 'start'],
            'cancelled' => ['cancel', 'changed', 'status'],
            'the type' => ['type', 'changed', 'type'],
            'moved to another company' => ['move', 'removed', 'type'],
            'ended before the month' => ['before', 'removed', 'end'],
        ];
    }

    #[DataProvider('assignmentChanges')]
    public function test_each_kind_of_assignment_change_is_detected_and_described(string $case, string $change, string $field): void
    {
        $this->generate($this->alpha);

        match ($case) {
            'end' => $this->annAssignment->forceFill(['end_date' => '2026-10-20'])->save(),
            'start' => $this->annAssignment->forceFill(['start_date' => '2026-10-10'])->save(),
            'cancel' => $this->annAssignment->forceFill(['status' => 'cancelled'])->save(),
            'type' => $this->annAssignment->forceFill(['assignment_type' => 'replacement', 'replaces_assignment_id' => $this->bobAssignment->id])->save(),
            'move' => $this->annAssignment->forceFill(['client_company_id' => $this->bravo->id])->save(),
            'before' => $this->annAssignment->forceFill(['end_date' => '2026-09-30'])->save(),
        };

        $changes = $this->assertStale($this->alpha, 'assignment', $change);
        $row = collect($changes)->firstWhere('assignment_id', $this->annAssignment->id);
        $this->assertNotNull($row, 'the assignment is named');
        $this->assertSame($this->ann->employee_number, $row['employee_number'], 'the employee is named');
        $this->assertContains($field, array_column($row['fields'], 'field'), 'the field is named');
    }

    public function test_the_answer_says_what_changed_in_words_and_numbers(): void
    {
        $this->generate($this->alpha);
        $this->annAssignment->forceFill(['end_date' => '2026-10-20'])->save();

        $response = $this->review($this->alpha)->assertStatus(409);
        $this->assertStringContainsString('Alpha Co', $response->json('message'));
        $this->assertStringContainsString('1 assignment change(s)', $response->json('message'));
        $this->assertStringContainsString('Generate Alpha Co again, then review and finalize it', $response->json('message'));
        $this->assertStringContainsString('Nothing was changed', $response->json('message'));
        $this->assertSame(1, $response->json('errors.total'));
        $this->assertSame(['field' => 'end', 'before' => null, 'after' => '2026-10-20'], collect($response->json('errors.changes.0.fields'))->firstWhere('field', 'end'));
    }

    public function test_a_new_assignment_in_the_company_that_month_is_detected(): void
    {
        $this->generate($this->alpha);
        $newcomer = $this->makeEmployee();
        $this->makeAssignment($newcomer, $this->alpha, null, 150, '2026-10-05');

        $changes = $this->assertStale($this->alpha, 'assignment', 'added');
        $this->assertSame($newcomer->employee_number, collect($changes)->firstWhere('change', 'added')['employee_number']);
    }

    public function test_a_new_assignment_in_another_company_or_starting_after_the_month_is_not_a_change(): void
    {
        $this->generate($this->alpha);
        $this->makeAssignment($this->makeEmployee(), $this->bravo, null, 150, '2026-01-01');   // another company
        $this->makeAssignment($this->makeEmployee(), $this->alpha, null, 150, '2026-11-10');   // starts after the month

        $this->assertFresh($this->alpha);
        $this->review($this->alpha)->assertOk();
    }

    // ------------------------------------------------------------------------------------------------ salary changes

    public function test_a_salary_row_that_only_takes_effect_after_the_month_is_not_a_change(): void
    {
        $this->generate($this->alpha);
        $this->annAssignment->salaryHistories()->create(['effective_from' => '2026-11-20', 'amount' => 999, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'Next month']);

        $this->assertFresh($this->alpha);
        $this->review($this->alpha)->assertOk();
    }

    public function test_a_salary_row_added_in_the_month_is_detected_and_described(): void
    {
        $this->generate($this->alpha);
        $this->annAssignment->salaryHistories()->create(['effective_from' => '2026-10-15', 'amount' => 350, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'Raise']);

        $row = collect($this->assertStale($this->alpha, 'salary', 'added'))->firstWhere('category', 'salary');
        $this->assertSame([$this->annAssignment->id, $this->ann->employee_number, '2026-10-15'], [$row['assignment_id'], $row['employee_number'], $row['detail']]);
        $this->assertSame(['field' => 'amount', 'before' => null, 'after' => '350.00'], collect($row['fields'])->firstWhere('field', 'amount'));
    }

    public function test_a_salary_row_changed_in_the_month_is_detected_with_before_and_after(): void
    {
        $this->generate($this->alpha);
        $this->annAssignment->salaryHistories()->orderBy('id')->firstOrFail()->forceFill(['amount' => 320])->save();

        $changed = collect($this->assertStale($this->alpha, 'salary', 'changed'))->firstWhere('category', 'salary');
        $this->assertSame(['field' => 'amount', 'before' => '300.00', 'after' => '320.00'], collect($changed['fields'])->firstWhere('field', 'amount'));
    }

    public function test_a_salary_row_removed_is_detected(): void
    {
        $this->generate($this->alpha);
        $this->bobAssignment->salaryHistories()->delete();

        $this->assertStale($this->alpha, 'salary', 'removed');
    }

    public function test_a_salary_change_of_another_company_does_not_block_this_one_but_blocks_its_own(): void
    {
        $this->generate($this->alpha);
        $this->generate($this->bravo);
        $this->cleoAssignment->salaryHistories()->create(['effective_from' => '2026-10-10', 'amount' => 450, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'Raise']);

        $this->assertFresh($this->alpha);
        $this->review($this->alpha)->assertOk();
        $this->assertStale($this->bravo, 'salary', 'added');
    }

    // ------------------------------------------------------------------------------------------------ Temporary Replacement changes

    private function cover(EmployeeWorkAssignment $assignment, array $override = []): TemporaryReplacement
    {
        $temp = $this->makeEmployee('active', 'temporary');

        return TemporaryReplacement::query()->create([
            'work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id, 'start_date' => '2026-10-10', 'end_date' => '2026-10-12', 'currency' => 'USD', 'status' => 'active', 'approved_at' => null, ...$override,
        ]);
    }

    public function test_a_temporary_cover_added_after_generate_is_detected(): void
    {
        $this->generate($this->alpha);
        $cover = $this->cover($this->annAssignment);

        $row = collect($this->assertStale($this->alpha, 'replacement', 'added'))->firstWhere('category', 'replacement');
        $this->assertSame([$cover->id, $this->annAssignment->id, $this->ann->employee_number], [$row['id'], $row['assignment_id'], $row['employee_number']]);
    }

    public function test_approving_a_cover_after_generate_is_detected_because_its_cost_starts_to_be_deducted(): void
    {
        $cover = $this->cover($this->annAssignment);
        $this->generate($this->alpha);
        $this->assertFresh($this->alpha);

        $cover->forceFill(['approved_at' => now()])->save();

        $changed = collect($this->assertStale($this->alpha, 'replacement', 'changed'))->firstWhere('category', 'replacement');
        $this->assertSame(['field' => 'approved', 'before' => 'no', 'after' => 'yes'], collect($changed['fields'])->firstWhere('field', 'approved'));
    }

    public function test_changing_the_dates_ending_or_replacing_the_worker_of_a_cover_is_detected(): void
    {
        $cover = $this->cover($this->annAssignment);
        $this->generate($this->alpha);
        $cover->forceFill(['end_date' => '2026-10-20'])->save();
        $this->assertStale($this->alpha, 'replacement', 'changed');

        $this->generate($this->alpha);
        $this->assertFresh($this->alpha);
        $cover->forceFill(['status' => 'ended'])->save();
        $this->assertStale($this->alpha, 'replacement', 'changed');

        $this->generate($this->alpha);
        $cover->forceFill(['replacement_employee_id' => $this->makeEmployee('active', 'temporary')->id])->save();
        $this->assertStale($this->alpha, 'replacement', 'changed');
    }

    public function test_covers_outside_the_month_or_of_another_company_do_not_block(): void
    {
        $this->generate($this->alpha);
        $this->cover($this->annAssignment, ['start_date' => '2026-08-01', 'end_date' => '2026-08-05']);   // ended before the month
        $this->cover($this->annAssignment, ['start_date' => '2026-11-10', 'end_date' => '2026-11-12']);   // starts after it
        $this->cover($this->cleoAssignment);                                                                // another company's position
        $this->assertFresh($this->alpha);
        $this->review($this->alpha)->assertOk();
    }

    public function test_unresolved_temporary_replacement_warnings_still_never_block_when_nothing_changed(): void
    {
        // An unapproved cover that already existed at Generate is a WARNING (conditions B / C), exactly as before: the guard only reacts to a CHANGE.
        $this->cover($this->annAssignment);
        $this->generate($this->alpha);

        $this->assertFresh($this->alpha);
        $this->warnings($this->alpha)->assertJsonPath('data.blocking', false);
        $this->review($this->alpha)->assertOk();
        $this->finalize($this->alpha)->assertOk();
    }

    // ------------------------------------------------------------------------------------------------ absence windows of an assignment

    public function test_an_absence_window_added_after_generate_is_detected(): void
    {
        $this->generate($this->alpha);
        AssignmentAbsence::query()->create(['work_assignment_id' => $this->annAssignment->id, 'last_working_day' => '2026-10-09', 'absent_from' => '2026-10-10', 'returned_on' => '2026-10-15', 'created_by' => $this->superId]);

        $this->assertStale($this->alpha, 'absence', 'added');
    }

    // ------------------------------------------------------------------------------------------------ what must NOT block

    public function test_changes_that_do_not_touch_the_pay_never_block(): void
    {
        $this->generate($this->alpha);

        // The employee's profile, a note, the assignment's bookkeeping timestamp, and attendance / leave marks are not source data of this guard.
        $this->ann->forceFill(['phone' => '+252611999888'])->save();
        $this->annAssignment->forceFill(['updated_at' => now()->addMinute()])->save();
        $this->annAssignment->touch();
        EmployeeWorkAssignment::query()->whereKey($this->annAssignment->id)->update(['salary_amount' => 300]);

        $this->assertFresh($this->alpha);
        $this->review($this->alpha)->assertOk();
        $this->finalize($this->alpha)->assertOk();
    }

    public function test_setting_a_workplace_of_the_same_company_does_not_block(): void
    {
        $this->generate($this->alpha);
        $this->annAssignment->forceFill(['work_location_id' => $this->makeClientLocation($this->alpha, 'Alpha Main')->id])->save();

        $this->assertFresh($this->alpha);
        $this->review($this->alpha)->assertOk();
    }

    public function test_moving_an_assignment_to_a_workplace_of_another_company_is_a_change(): void
    {
        $this->generate($this->alpha);
        $this->annAssignment->forceFill(['client_company_id' => null, 'work_location_id' => $this->makeClientLocation($this->bravo, 'Bravo Main')->id])->save();

        $this->assertStale($this->alpha, 'assignment', 'removed');
    }

    // ------------------------------------------------------------------------------------------------ payroll states

    public function test_a_reviewed_company_cannot_be_finalized_after_a_change_and_stays_reviewed(): void
    {
        $this->generate($this->alpha);
        $this->review($this->alpha)->assertOk();
        $this->annAssignment->forceFill(['end_date' => '2026-10-20'])->save();

        $this->finalize($this->alpha)->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_SOURCES_CHANGED');
        $this->assertSame('reviewed', $this->section($this->alpha)->status);

        // Generate again, review, finalize: the normal path out.
        $this->generate($this->alpha);
        $this->review($this->alpha)->assertOk();
        $this->finalize($this->alpha)->assertOk();
    }

    public function test_a_finalized_company_is_never_re_judged_rewritten_or_unpaid_by_a_later_change(): void
    {
        $this->generate($this->alpha);
        $this->review($this->alpha)->assertOk();
        $this->finalize($this->alpha)->assertOk();
        $this->approvePaid($this->section($this->alpha)->id, $this->hr, 'paid-alpha-0001', '2026-11-05')->assertSuccessful();

        $snapshot = $this->section($this->alpha)->generation_inputs;
        $lines = PayrollItem::query()->where('section_id', $this->section($this->alpha)->id)->orderBy('id')->pluck('net_cents', 'id')->all();
        $payments = PayrollWorkplacePayment::query()->orderBy('id')->pluck('amount_cents', 'id')->all();

        $this->annAssignment->forceFill(['end_date' => '2026-10-20'])->save();
        $this->annAssignment->salaryHistories()->create(['effective_from' => '2026-10-15', 'amount' => 350, 'currency' => 'USD', 'frequency' => 'monthly', 'reason' => 'Raise']);
        $this->cover($this->annAssignment);

        // Nothing was rewritten, recalculated or flagged: the finalized history and the payment record stay exactly as they were.
        $this->warnings($this->alpha)->assertJsonPath('data.source_inputs_changed', false);
        $this->assertSame('finalized', $this->section($this->alpha)->status);
        $this->assertSame($snapshot, $this->section($this->alpha)->generation_inputs);
        $this->assertSame($lines, PayrollItem::query()->where('section_id', $this->section($this->alpha)->id)->orderBy('id')->pluck('net_cents', 'id')->all());
        $this->assertSame($payments, PayrollWorkplacePayment::query()->orderBy('id')->pluck('amount_cents', 'id')->all());

        // And Generate still refuses to rebuild it.
        $this->as($this->super)->json('POST', '/api/v1/admin/hr/payroll/'.self::P."/companies/{$this->alpha->id}/generate")->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_COMPANY_LOCKED');
    }

    // Owner decision O2 (2026-11-07) replaced the earlier behaviour "a section without a snapshot is judged as before": it is now REFUSED until Generate stores one
    // (the full set of cases is in PayrollSnapshotRequiredTest). The warnings read still reports "no change" for such a section because there is nothing to compare.
    public function test_a_section_generated_before_the_guard_existed_has_no_snapshot_and_must_be_generated_again(): void
    {
        $this->generate($this->alpha);
        PayrollRunSection::query()->whereKey($this->section($this->alpha)->id)->update(['generation_inputs' => null]);
        $this->annAssignment->forceFill(['end_date' => '2026-10-20'])->save();

        $this->assertFresh($this->alpha);
        $this->review($this->alpha)->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_SNAPSHOT_MISSING');
        $this->assertSame('draft', $this->section($this->alpha)->status);

        $this->generate($this->alpha);
        $this->review($this->alpha)->assertOk();
        $this->finalize($this->alpha)->assertOk();
    }

    public function test_a_legacy_approved_section_without_a_snapshot_is_untouched_by_changes_and_cannot_finalize_until_regenerated(): void
    {
        $this->generate($this->alpha);
        $section = $this->section($this->alpha);
        $section->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => null, 'generation_inputs' => null])->save();
        $this->annAssignment->forceFill(['end_date' => '2026-10-20'])->save();

        $this->warnings($this->alpha)->assertJsonPath('data.source_inputs_changed', false);
        $this->finalize($this->alpha)->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_SNAPSHOT_MISSING');
        $this->assertSame('approved', $this->section($this->alpha)->status);
    }

    // ------------------------------------------------------------------------------------------------ both guards, and the size of the answer

    public function test_the_deduction_guard_and_the_source_guard_both_apply_and_both_clear_on_generate(): void
    {
        $this->generate($this->alpha);
        $this->as($this->hr)->postJson("/api/v1/admin/hr/employees/{$this->ann->id}/penalties", ['penalty_date' => '2026-10-10', 'reason' => 'Late again', 'deduction_amount' => 20, 'currency' => 'USD', 'payroll_period' => self::P])->assertCreated();
        $this->annAssignment->forceFill(['end_date' => '2026-10-20'])->save();

        $this->review($this->alpha)->assertStatus(409);
        $warnings = $this->warnings($this->alpha);
        $warnings->assertJsonPath('data.deduction_inputs_changed', true)->assertJsonPath('data.source_inputs_changed', true);

        $this->generate($this->alpha);
        $this->review($this->alpha)->assertOk();
    }

    public function test_a_large_number_of_changes_lists_the_first_fifty_and_reports_the_real_total(): void
    {
        $this->generate($this->alpha);

        for ($i = 0; $i < 52; $i++) {
            $this->makeAssignment($this->makeEmployee(), $this->alpha, null, 100, '2026-10-05');
        }

        // 52 new assignments, each with its own salary row: 104 changes in all; only the first 50 are listed and the real total is reported.
        $response = $this->review($this->alpha)->assertStatus(409);
        $this->assertSame(104, $response->json('errors.total'));
        $this->assertCount(50, $response->json('errors.changes'));
        $this->warnings($this->alpha)->assertJsonPath('data.source_changes_total', 104);
        $this->assertStringContainsString('52 assignment change(s), 52 salary change(s)', $response->json('message'));
    }

    public function test_the_guard_reads_only_so_a_refused_review_changes_nothing(): void
    {
        $this->generate($this->alpha);
        $before = [$this->section($this->alpha)->only(['status', 'generation_inputs', 'totals']), PayrollItem::query()->orderBy('id')->pluck('net_cents', 'id')->all()];
        $this->annAssignment->forceFill(['end_date' => '2026-10-20'])->save();

        $this->review($this->alpha)->assertStatus(409);
        $this->warnings($this->alpha)->assertOk();

        $this->assertSame($before, [$this->section($this->alpha)->only(['status', 'generation_inputs', 'totals']), PayrollItem::query()->orderBy('id')->pluck('net_cents', 'id')->all()]);
    }
}
