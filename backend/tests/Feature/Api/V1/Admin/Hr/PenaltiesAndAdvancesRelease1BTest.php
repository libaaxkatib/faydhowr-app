<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeePenalty;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollItemDeduction;
use App\Models\PayrollRunSection;
use App\Support\Hr\Payroll\CorrectionAuthority;
use App\Support\Hr\Payroll\DeductionBalances;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * HR Release 1B (owner decisions P-1, A-1, Q6, Q7, Q8, Q11, U-1 / R-1): penalties and one-month advances are registered once (idempotent, duplicate-guarded),
 * deducted only up to the net of $0 with the rest staying owed and carried into the next months, never lost and never forgiven by themselves, voided or
 * corrected only while no finalized payroll has deducted them, waived by a Super Admin alone, and a draft whose penalties/advances changed cannot be
 * reviewed or finalized. Synthetic data only.
 */
class PenaltiesAndAdvancesRelease1BTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const OCT = '2026-10';

    private const NOV = '2026-11';

    private ClientCompany $alpha;

    private ClientCompany $bravo;

    private Employee $ann;

    private Employee $ben;

    private string $super;

    private string $hr;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-12-05 10:00:00');
        [, $this->super] = $this->superAdminToken();
        $this->hr = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve']);

        $this->alpha = $this->makeCompany('Alpha Co');
        $this->bravo = $this->makeCompany('Bravo Co');
        $this->ann = $this->makeEmployee();
        $this->ben = $this->makeEmployee();
        $this->makeAssignment($this->ann, $this->alpha, null, 300, '2026-01-01');
        $this->makeAssignment($this->ben, $this->bravo, null, 200, '2026-01-01');
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

    private function penalty(Employee $e, float|int|string $amount, string $period = self::OCT, array $extra = [], ?string $token = null)
    {
        return $this->as($token ?? $this->hr)->postJson("/api/v1/admin/hr/employees/{$e->id}/penalties", [
            'penalty_date' => $period.'-10', 'reason' => 'Late again', 'deduction_amount' => $amount, 'currency' => 'USD', 'payroll_period' => $period, ...$extra,
        ]);
    }

    private function advance(Employee $e, float|int $amount, string $period = self::OCT, array $extra = [], ?string $token = null)
    {
        return $this->as($token ?? $this->hr)->postJson("/api/v1/admin/hr/employees/{$e->id}/advances", [
            'advance_date' => $period.'-12', 'reason' => 'Rent', 'amount' => $amount, 'currency' => 'USD', 'payroll_period' => $period, ...$extra,
        ]);
    }

    private function generate(ClientCompany $c, string $period): void
    {
        $this->as($this->super)->json('POST', "/api/v1/admin/hr/payroll/{$period}/companies/{$c->id}/generate")->assertCreated();
    }

    private function section(ClientCompany $c, string $period): PayrollRunSection
    {
        return PayrollRunSection::query()->whereHas('run', fn ($q) => $q->where('period', $period))->where('client_company_id', $c->id)->firstOrFail();
    }

    private function finalize(ClientCompany $c, string $period, ?string $token = null)
    {
        $id = $this->section($c, $period)->id;
        $this->as($token ?? $this->hr)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$id}/review")->assertOk();

        return $this->as($token ?? $this->hr)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$id}/finalize");
    }

    private function line(ClientCompany $c, string $period, Employee $e): PayrollItem
    {
        return PayrollItem::query()->where('section_id', $this->section($c, $period)->id)->where('employee_id', $e->id)->firstOrFail();
    }

    /** @return array<string, PayrollItemDeduction> kind => deduction (one of each kind per line in these tests) */
    private function deductions(PayrollItem $item): array
    {
        return PayrollItemDeduction::query()->where('payroll_item_id', $item->id)->get()->keyBy('kind')->all();
    }

    private function owedNow(string $kind, Employee $e, string $period): int
    {
        $owed = DeductionBalances::owedFor([$e->id], $period);

        return (int) ($owed[$kind === 'penalty' ? 'penalties' : 'advances'][$e->id] ?? collect())->sum('owed_cents');
    }

    // ------------------------------------------------------------------------------------------------ P-1: never below $0, the rest stays owed

    public function test_a_penalty_larger_than_the_net_takes_only_the_net_and_the_rest_stays_owed(): void
    {
        $this->penalty($this->ann, 350)->assertCreated();
        $this->generate($this->alpha, self::OCT);

        $line = $this->line($this->alpha, self::OCT, $this->ann);
        $penalty = $this->deductions($line)['penalty'];
        $this->assertSame(0, (int) $line->net_cents, 'the net is never below 0');
        $this->assertSame(30000, (int) $penalty->amount_cents);
        $this->assertSame(35000, $penalty->detail['owed_cents']);
        $this->assertSame(5000, $penalty->detail['not_deducted_cents']);
        $this->assertTrue($penalty->detail['capped_at_zero_net']);
        $this->assertStringContainsString('stays owed', $penalty->description);
        $this->assertContains('Penalty #'.EmployeePenalty::query()->value('id').' of '.$this->ann->employee_number.': 350.00 owed, 300.00 deducted (the net is never below 0); 50.00 stays owed and carries to the next month.', PayrollItem::query()->first()->run->totals['warnings']);
    }

    public function test_the_unpaid_balance_is_carried_into_the_next_month_until_it_is_zero_and_never_deducted_twice(): void
    {
        $this->penalty($this->ann, 350)->assertCreated();
        $this->generate($this->alpha, self::OCT);
        $this->finalize($this->alpha, self::OCT)->assertOk();

        // November: the 50.00 that October could not take is deducted, once.
        $this->generate($this->alpha, self::NOV);
        $nov = $this->deductions($this->line($this->alpha, self::NOV, $this->ann))['penalty'];
        $this->assertSame(5000, (int) $nov->amount_cents);
        $this->assertTrue($nov->detail['carried_in']);
        $this->assertSame('2026-10', $nov->detail['registered_period']);
        $this->assertSame(30000, $nov->detail['previously_deducted_cents']);
        $this->assertSame(25000, (int) $this->line($this->alpha, self::NOV, $this->ann)->net_cents);
        $this->assertStringContainsString('carried from 2026-10', $nov->description);

        // Regenerating November (still a draft) gives the same line, not a second deduction.
        $this->generate($this->alpha, self::NOV);
        $this->assertSame(1, PayrollItemDeduction::query()->where('source_type', 'employee_penalty')->where('period', self::NOV)->count());
        $this->assertSame(5000, (int) $this->deductions($this->line($this->alpha, self::NOV, $this->ann))['penalty']->amount_cents);

        $this->finalize($this->alpha, self::NOV)->assertOk();

        // December: nothing is owed any more; the total taken is exactly the penalty.
        $this->assertSame(0, $this->owedNow('penalty', $this->ann, '2026-12'));
        $this->assertSame(35000, (int) PayrollItemDeduction::query()->where('source_type', 'employee_penalty')->sum('amount_cents'));
    }

    public function test_a_one_month_advance_is_zero_floored_and_its_remainder_stays_outstanding_and_carries(): void
    {
        $this->advance($this->ann, 450)->assertCreated();
        $this->generate($this->alpha, self::OCT);

        $this->assertSame(30000, (int) $this->deductions($this->line($this->alpha, self::OCT, $this->ann))['advance']->amount_cents);
        $this->assertSame(0, (int) $this->line($this->alpha, self::OCT, $this->ann)->net_cents);
        $this->finalize($this->alpha, self::OCT)->assertOk();

        $this->generate($this->alpha, self::NOV);
        $nov = $this->deductions($this->line($this->alpha, self::NOV, $this->ann))['advance'];
        $this->assertSame(15000, (int) $nov->amount_cents);
        $this->assertSame(15000, (int) $this->line($this->alpha, self::NOV, $this->ann)->net_cents);
    }

    public function test_penalties_come_before_advances_and_both_stay_inside_the_net(): void
    {
        $this->penalty($this->ann, 100)->assertCreated();
        $this->advance($this->ann, 250)->assertCreated();
        $this->generate($this->alpha, self::OCT);

        $d = $this->deductions($this->line($this->alpha, self::OCT, $this->ann));
        $this->assertSame([10000, 20000], [(int) $d['penalty']->amount_cents, (int) $d['advance']->amount_cents]);
        $this->assertSame(0, (int) $this->line($this->alpha, self::OCT, $this->ann)->net_cents);
        $this->assertSame(5000, $d['advance']->detail['not_deducted_cents']);
    }

    public function test_a_draft_never_counts_as_deducted_so_finalizing_the_later_month_first_makes_the_earlier_one_stale(): void
    {
        $this->penalty($this->ann, 350)->assertCreated();
        $this->generate($this->alpha, self::OCT);   // draft: provisional
        $this->generate($this->alpha, self::NOV);   // sees the whole 350 as still owed
        $this->assertSame(30000, (int) $this->deductions($this->line($this->alpha, self::NOV, $this->ann))['penalty']->amount_cents);

        $this->finalize($this->alpha, self::NOV)->assertOk();

        // October would now deduct 300 on top of November's 300: refused until it is generated again, which deducts the 50 left.
        $id = $this->section($this->alpha, self::OCT)->id;
        $this->as($this->hr)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$id}/review")->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_INPUTS_CHANGED');
        $this->generate($this->alpha, self::OCT);
        $this->assertSame(5000, (int) $this->deductions($this->line($this->alpha, self::OCT, $this->ann))['penalty']->amount_cents);
        $this->finalize($this->alpha, self::OCT)->assertOk();
        $this->assertSame(35000, (int) PayrollItemDeduction::query()->where('source_type', 'employee_penalty')->sum('amount_cents'), 'never more than the penalty in total');
    }

    public function test_reopening_a_finalized_month_puts_its_deduction_back_as_owed(): void
    {
        $this->penalty($this->ann, 350)->assertCreated();
        $this->generate($this->alpha, self::OCT);
        $this->finalize($this->alpha, self::OCT)->assertOk();
        $this->assertSame(5000, $this->owedNow('penalty', $this->ann, self::NOV));

        $id = $this->section($this->alpha, self::OCT)->id;
        $this->as($this->hr)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$id}/reopen", ['reason' => 'Fix the October penalty before payment'])->assertOk();

        // October is a draft again: its lines no longer count as deducted, so the whole amount is owed again - nothing is counted twice.
        $this->assertSame(35000, $this->owedNow('penalty', $this->ann, self::NOV));
    }

    // ------------------------------------------------------------------------------------------------ Q6: leavers

    public function test_a_leavers_balance_stays_visible_and_is_never_forgiven_or_turned_into_debt(): void
    {
        $this->penalty($this->ann, 350)->assertCreated();
        $this->generate($this->alpha, self::OCT);
        $this->finalize($this->alpha, self::OCT)->assertOk();

        // Ann leaves at the end of October; Alpha keeps another employee, so it still has a November payroll.
        $this->makeAssignment($this->makeEmployee(), $this->alpha, null, 100, '2026-01-01');
        EmployeeWorkAssignment::query()->where('employee_id', $this->ann->id)->update(['end_date' => '2026-10-31', 'status' => 'ended']);

        $this->generate($this->alpha, self::NOV);
        $this->assertSame(0, PayrollItem::query()->where('section_id', $this->section($this->alpha, self::NOV)->id)->where('employee_id', $this->ann->id)->count(), 'a leaver has no November line');
        $this->assertSame(5000, $this->owedNow('penalty', $this->ann, self::NOV), 'still owed');

        $this->as($this->hr)->getJson('/api/v1/admin/hr/payroll/deduction-balances')->assertOk()
            ->assertJsonPath('data.totals.count', 1)
            ->assertJsonPath('data.totals.leaver_count', 1)
            ->assertJsonPath('data.totals.leaver_owed_cents', 5000)
            ->assertJsonPath('data.rows.0.leaver', true)
            ->assertJsonPath('data.rows.0.state', 'partly_deducted')
            ->assertJsonPath('data.rows.0.owed_cents', 5000);
        $this->as($this->hr)->getJson('/api/v1/admin/hr/payroll/deduction-balances?leavers_only=1&kind=advance')->assertOk()->assertJsonPath('data.totals.count', 0);

        $this->assertDatabaseCount('payroll_corrections', 0); // no formal debt in Release 1
    }

    public function test_the_balances_list_needs_the_payroll_view_permission(): void
    {
        $this->as($this->tokenWithOnly(['hr.view']))->getJson('/api/v1/admin/hr/payroll/deduction-balances')->assertForbidden();
    }

    // ------------------------------------------------------------------------------------------------ U-1: idempotency, duplicates, validation

    public function test_the_same_request_sent_twice_records_one_penalty(): void
    {
        $this->penalty($this->ann, 40, self::OCT, ['idempotency_key' => 'pen-key-0001'])->assertCreated();
        $this->penalty($this->ann, 40, self::OCT, ['idempotency_key' => 'pen-key-0001'])->assertOk()->assertJsonCount(1, 'data.penalties');
        $this->assertSame(1, EmployeePenalty::query()->count());

        // The key used for something else is refused, never merged.
        $this->penalty($this->ann, 99, self::OCT, ['idempotency_key' => 'pen-key-0001'])->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REUSED');
        $this->penalty($this->ben, 40, self::OCT, ['idempotency_key' => 'pen-key-0001'])->assertStatus(409);
        $this->assertSame(1, EmployeePenalty::query()->count());
    }

    public function test_the_same_advance_sent_twice_is_refused_as_a_duplicate_unless_confirmed_but_different_advances_are_fine(): void
    {
        $this->advance($this->ann, 50)->assertCreated();
        $this->advance($this->ann, 50)->assertStatus(409)->assertJsonPath('error_code', 'DUPLICATE_RECORD');
        $this->advance($this->ann, 30)->assertCreated();
        $this->advance($this->ann, 50, self::OCT, ['confirm_duplicate' => true])->assertCreated();
        $this->assertSame(3, EmployeeAdvance::query()->count());
    }

    public function test_the_amount_currency_date_month_and_reason_are_validated(): void
    {
        foreach ([0, -5, 12.345, 'abc', 2000000] as $bad) {
            $this->penalty($this->ann, $bad)->assertStatus(422)->assertJsonValidationErrors('deduction_amount');
        }
        $this->penalty($this->ann, 10, self::OCT, ['currency' => 'EUR'])->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->penalty($this->ann, 10, self::OCT, ['payroll_period' => '2027-06'])->assertStatus(422)->assertJsonValidationErrors('payroll_period');
        $this->penalty($this->ann, 10, self::OCT, ['payroll_period' => '2019-12'])->assertStatus(422)->assertJsonValidationErrors('payroll_period');
        $this->penalty($this->ann, 10, self::OCT, ['penalty_date' => '2062-01-01'])->assertStatus(422)->assertJsonValidationErrors('penalty_date');
        $this->penalty($this->ann, 10, self::OCT, ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->advance($this->ann, 10, self::OCT, ['currency' => 'SOS'])->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->advance($this->ann, 0.5)->assertCreated();
        $this->penalty($this->ann, 'usd-case', self::OCT)->assertStatus(422);
        $this->penalty($this->ann, 12.5, self::OCT, ['currency' => 'usd'])->assertCreated();
        $this->assertSame(0, EmployeePenalty::query()->where('deduction_amount', '<=', 0)->count());
    }

    // ------------------------------------------------------------------------------------------------ Q11 (Option B)

    public function test_a_former_employee_can_be_charged_for_a_month_they_were_assigned_in_but_not_for_one_they_were_not(): void
    {
        EmployeeWorkAssignment::query()->where('employee_id', $this->ann->id)->update(['end_date' => '2026-10-20', 'status' => 'ended']);
        $this->ann->forceFill(['status' => 'inactive'])->save();

        $this->penalty($this->ann, 25, self::OCT)->assertCreated();
        $this->penalty($this->ann, 25, '2026-12', ['penalty_date' => '2026-12-01'])->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');

        // The October line exists because the assignment overlapped the month: the penalty reaches it.
        $this->generate($this->alpha, self::OCT);
        $this->assertSame(2500, (int) $this->deductions($this->line($this->alpha, self::OCT, $this->ann))['penalty']->amount_cents);
    }

    // ------------------------------------------------------------------------------------------------ freshness guard

    public function test_a_penalty_registered_after_generate_must_be_generated_again_before_review_and_finalize(): void
    {
        $this->generate($this->alpha, self::OCT);
        $id = $this->section($this->alpha, self::OCT)->id;
        $this->as($this->hr)->getJson('/api/v1/admin/hr/payroll/'.self::OCT."/companies/{$this->alpha->id}/warnings")->assertOk()->assertJsonPath('data.deduction_inputs_changed', false);

        $this->penalty($this->ann, 40)->assertCreated();

        $this->as($this->hr)->getJson('/api/v1/admin/hr/payroll/'.self::OCT."/companies/{$this->alpha->id}/warnings")->assertOk()->assertJsonPath('data.deduction_inputs_changed', true);
        $this->as($this->hr)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$id}/review")->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_INPUTS_CHANGED');
        $this->assertSame('draft', $this->section($this->alpha, self::OCT)->status);

        $this->generate($this->alpha, self::OCT);
        $this->finalize($this->alpha, self::OCT)->assertOk();
        $this->assertSame(4000, (int) $this->deductions($this->line($this->alpha, self::OCT, $this->ann))['penalty']->amount_cents);
    }

    public function test_voiding_or_waiving_after_generate_also_makes_the_draft_stale_and_a_section_without_a_fingerprint_is_judged_as_before(): void
    {
        $this->penalty($this->ann, 40)->assertCreated();
        $this->generate($this->alpha, self::OCT);
        $penalty = EmployeePenalty::query()->firstOrFail();
        $id = $this->section($this->alpha, self::OCT)->id;

        $this->as($this->hr)->postJson("/api/v1/admin/hr/employees/{$this->ann->id}/penalties/{$penalty->id}/void", ['reason' => 'Entered by mistake', 'expected_version' => 1])->assertOk();
        $this->as($this->hr)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$id}/review")->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_INPUTS_CHANGED');

        // A section generated before this release has no fingerprint: not judged by it (the locked sections of earlier months stay as they are).
        PayrollRunSection::query()->whereKey($id)->update(['deduction_inputs_hash' => null]);
        $this->as($this->hr)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$id}/review")->assertOk();
    }

    // ------------------------------------------------------------------------------------------------ Q8: void / correct / waive

    public function test_an_hr_manager_voids_an_unapplied_penalty_with_a_reason_and_it_stops_counting(): void
    {
        $this->penalty($this->ann, 40)->assertCreated();
        $penalty = EmployeePenalty::query()->firstOrFail();
        $url = "/api/v1/admin/hr/employees/{$this->ann->id}/penalties/{$penalty->id}/void";

        $this->as($this->hr)->postJson($url, ['expected_version' => 1])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($this->hr)->postJson($url, ['reason' => 'Entered by mistake', 'expected_version' => 7])->assertStatus(409)->assertJsonPath('error_code', 'RECORD_CHANGED');
        $this->as($this->hr)->postJson($url, ['reason' => 'Entered by mistake', 'expected_version' => 1])->assertOk();

        $penalty->refresh();
        $this->assertSame(['voided', 2, 'Entered by mistake'], [$penalty->status, $penalty->version, $penalty->void_reason]);
        $this->assertNotNull($penalty->voided_at);
        $this->assertSame(1, EmployeePenalty::query()->count(), 'the row stays in the history');
        $this->as($this->hr)->postJson($url, ['reason' => 'again please', 'expected_version' => 2])->assertStatus(422)->assertJsonPath('error_code', 'RECORD_NOT_ACTIVE');

        $this->generate($this->alpha, self::OCT);
        $this->assertArrayNotHasKey('penalty', $this->deductions($this->line($this->alpha, self::OCT, $this->ann)));

        $audit = AuditLog::query()->where('description', 'like', 'Penalty #%voided%')->firstOrFail();
        $this->assertSame('active', $audit->metadata['before']['status']);
        $this->assertSame('voided', $audit->metadata['after']['status']);
        $this->assertSame('hr_manager', $audit->metadata['actor_role']);
    }

    public function test_an_ordinary_hr_user_cannot_void_correct_or_waive(): void
    {
        $this->penalty($this->ann, 40)->assertCreated();
        $penalty = EmployeePenalty::query()->firstOrFail();
        $plain = $this->tokenWithOnly(['hr.view', 'hr.manage']);

        $this->as($plain)->postJson("/api/v1/admin/hr/employees/{$this->ann->id}/penalties/{$penalty->id}/void", ['reason' => 'Entered by mistake', 'expected_version' => 1])
            ->assertStatus(403)->assertJsonPath('error_code', 'DEDUCTION_CHANGE_FORBIDDEN');
        $this->as($plain)->postJson("/api/v1/admin/hr/employees/{$this->ann->id}/penalties/{$penalty->id}/correct", ['deduction_amount' => 10, 'correction_reason' => 'Wrong amount', 'expected_version' => 1])
            ->assertStatus(403);
        $this->assertSame('active', $penalty->fresh()->status);
    }

    public function test_correcting_voids_the_old_row_and_saves_a_replacement_that_points_back(): void
    {
        $this->advance($this->ann, 80)->assertCreated();
        $old = EmployeeAdvance::query()->firstOrFail();
        $base = "/api/v1/admin/hr/employees/{$this->ann->id}/advances/{$old->id}/correct";

        $this->as($this->hr)->postJson($base, ['amount' => 60, 'expected_version' => 1])->assertStatus(422)->assertJsonValidationErrors('correction_reason');
        $this->as($this->hr)->postJson($base, ['amount' => 60.555, 'correction_reason' => 'Wrong amount', 'expected_version' => 1])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->as($this->hr)->postJson($base, ['amount' => 60, 'correction_reason' => 'Wrong amount', 'expected_version' => 9])->assertStatus(409);
        $this->as($this->hr)->postJson($base, ['amount' => 60, 'correction_reason' => 'Wrong amount', 'expected_version' => 1])->assertCreated();

        $new = EmployeeAdvance::query()->where('status', 'active')->firstOrFail();
        $this->assertSame([60.0, $old->id, 1, 'Rent'], [(float) $new->amount, $new->replaces_id, $new->version, $new->reason]);
        $this->assertSame('voided', $old->fresh()->status);
        $this->assertStringContainsString('Corrected: Wrong amount', $old->fresh()->void_reason);

        $this->generate($this->alpha, self::OCT);
        $this->assertSame(6000, (int) $this->deductions($this->line($this->alpha, self::OCT, $this->ann))['advance']->amount_cents);
    }

    public function test_a_record_a_finalized_payroll_already_deducted_cannot_be_voided_or_corrected_in_place(): void
    {
        $this->penalty($this->ann, 40)->assertCreated();
        $penalty = EmployeePenalty::query()->firstOrFail();
        $this->generate($this->alpha, self::OCT);
        $this->finalize($this->alpha, self::OCT)->assertOk();

        $this->as($this->super)->postJson("/api/v1/admin/hr/employees/{$this->ann->id}/penalties/{$penalty->id}/void", ['reason' => 'Entered by mistake', 'expected_version' => 1])
            ->assertStatus(422)->assertJsonPath('error_code', 'DEDUCTION_ALREADY_APPLIED');
        $this->as($this->super)->postJson("/api/v1/admin/hr/employees/{$this->ann->id}/penalties/{$penalty->id}/correct", ['deduction_amount' => 10, 'correction_reason' => 'Wrong amount', 'expected_version' => 1])
            ->assertStatus(422)->assertJsonPath('error_code', 'DEDUCTION_ALREADY_APPLIED');

        // A new record for a closed company-month is refused as before.
        $this->penalty($this->ann, 5)->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_PERIOD_LOCKED');
        $this->assertSame('active', $penalty->fresh()->status);
    }

    public function test_reversing_an_applied_deduction_after_a_reopening_is_for_a_super_admin_only(): void
    {
        $this->penalty($this->ann, 40)->assertCreated();
        $penalty = EmployeePenalty::query()->firstOrFail();
        $this->generate($this->alpha, self::OCT);
        $this->finalize($this->alpha, self::OCT)->assertOk();
        $id = $this->section($this->alpha, self::OCT)->id;
        $this->as($this->hr)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$id}/reopen", ['reason' => 'The penalty was wrong, reopening to fix it'])->assertOk();

        $url = "/api/v1/admin/hr/employees/{$this->ann->id}/penalties/{$penalty->id}/void";
        $this->as($this->hr)->postJson($url, ['reason' => 'Entered by mistake', 'expected_version' => 1])->assertStatus(403)->assertJsonPath('error_code', 'REVERSE_APPLIED_SUPER_ADMIN_ONLY');
        $this->as($this->super)->postJson($url, ['reason' => 'Entered by mistake', 'expected_version' => 1])->assertOk();
        $this->assertSame('voided', $penalty->fresh()->status);
    }

    public function test_only_a_super_admin_waives_a_balance_and_only_after_something_was_deducted(): void
    {
        $this->penalty($this->ann, 350)->assertCreated();
        $penalty = EmployeePenalty::query()->firstOrFail();
        $url = "/api/v1/admin/hr/employees/{$this->ann->id}/penalties/{$penalty->id}/waive";

        // Never deducted: that is a void, not a waive.
        $this->as($this->super)->postJson($url, ['reason' => 'Owner decided', 'expected_version' => 1])->assertStatus(422)->assertJsonPath('error_code', 'WAIVE_NOT_APPLICABLE');

        $this->generate($this->alpha, self::OCT);
        $this->finalize($this->alpha, self::OCT)->assertOk();

        $this->as($this->hr)->postJson($url, ['reason' => 'Owner decided', 'expected_version' => 1])->assertStatus(403)->assertJsonPath('error_code', 'WAIVE_SUPER_ADMIN_ONLY');
        $this->as($this->super)->postJson($url, ['reason' => 'Owner decided', 'amount' => 80, 'expected_version' => 1])->assertStatus(422)->assertJsonPath('error_code', 'WAIVE_AMOUNT_INVALID');
        $this->as($this->super)->postJson($url, ['reason' => 'Owner decided', 'amount' => 20, 'expected_version' => 1])->assertOk();

        $penalty->refresh();
        $this->assertSame([2000, 2], [$penalty->waived_cents, $penalty->version]);
        $this->assertSame(3000, $this->owedNow('penalty', $this->ann, self::NOV), '50.00 owed - 20.00 waived');

        // The waived part is not deducted in November; what was already deducted in October is untouched.
        $this->generate($this->alpha, self::NOV);
        $this->assertSame(3000, (int) $this->deductions($this->line($this->alpha, self::NOV, $this->ann))['penalty']->amount_cents);
        $this->assertSame(30000, (int) PayrollItemDeduction::query()->where('period', self::OCT)->where('source_type', 'employee_penalty')->sum('amount_cents'));
    }

    public function test_the_role_matrix_and_the_voided_rows_never_reach_reports(): void
    {
        $matrix = CorrectionAuthority::matrix();
        $this->assertSame(['super_admin' => true, 'hr_manager' => true], $matrix['void_or_correct_unapplied_penalty_or_advance']);
        $this->assertSame(['super_admin' => true, 'hr_manager' => false], $matrix['reverse_applied_deduction_or_waive_balance']);

        $this->penalty($this->ann, 40)->assertCreated();
        $penalty = EmployeePenalty::query()->firstOrFail();
        $this->as($this->hr)->postJson("/api/v1/admin/hr/employees/{$this->ann->id}/penalties/{$penalty->id}/void", ['reason' => 'Entered by mistake', 'expected_version' => 1])->assertOk();

        $this->as($this->super)->getJson('/api/v1/admin/hr/reports/financial-ledger')->assertOk()->assertJsonMissing(['reason' => 'Late again']);
        $this->assertSame(0, EmployeePenalty::query()->active()->count());
    }

    public function test_legacy_unpaid_deduction_of_one_company_is_not_taken_from_another_company(): void
    {
        // Ben works for Bravo only; Ann's penalty must never appear on his Bravo line.
        $this->penalty($this->ann, 40)->assertCreated();
        $this->generate($this->bravo, self::OCT);
        $this->assertArrayNotHasKey('penalty', $this->deductions($this->line($this->bravo, self::OCT, $this->ben)));
        $this->assertSame(20000, (int) $this->line($this->bravo, self::OCT, $this->ben)->net_cents);
    }
}
