<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAttendance;
use App\Models\EmployeePayment;
use App\Models\EmployeePenalty;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollItemDeduction;
use App\Models\PayrollRun;
use App\Models\TemporaryReplacement;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\HrFixtures;
use Tests\TestCase;

class PayrollLifecycleTest extends TestCase
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

    private function generate(string $token, string $period = self::P)
    {
        return $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period]);
    }

    /** @return array{0: Employee, 1: EmployeeWorkAssignment} */
    private function permanentWith(int $dollars = 300, string $start = '2026-01-01'): array
    {
        $employee = $this->makeEmployee('active', 'permanent');

        return [$employee, $this->makeAssignment($employee, $this->makeCompany(fake()->unique()->company()), null, $dollars, $start)];
    }

    private function cover(EmployeeWorkAssignment $assignment, array $days, bool $approved = true, bool $verified = true): TemporaryReplacement
    {
        $temp = $this->makeEmployee('active', 'temporary');
        $replacement = TemporaryReplacement::query()->create([
            'work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id,
            'start_date' => '2026-09-01', 'currency' => 'USD', 'status' => 'active', 'approved_at' => $approved ? now() : null,
        ]);

        foreach ($days as $day) {
            $replacement->workdays()->create([
                'work_assignment_id' => $assignment->id, 'replacement_employee_id' => $temp->id,
                'work_date' => sprintf('2026-09-%02d', $day), 'status' => $verified ? 'verified' : 'recorded',
            ]);
        }

        return $replacement;
    }

    public function test_generation_prorates_and_itemizes_deductions_exactly_once(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee, $assignment] = $this->permanentWith(300);
        $this->cover($assignment, range(3, 10)); // 8 verified days, 30-day month => 80.00
        EmployeePenalty::query()->create(['employee_id' => $employee->id, 'penalty_date' => '2026-09-05', 'reason' => 'Late', 'deduction_amount' => 15, 'currency' => 'USD', 'payroll_period' => self::P]);
        EmployeeAdvance::query()->create(['employee_id' => $employee->id, 'advance_date' => '2026-09-06', 'amount' => 20, 'currency' => 'USD', 'payroll_period' => self::P, 'reason' => 'Rent']);

        $response = $this->generate($token)->assertCreated();
        $item = $response->json('data');
        $this->assertSame(1, $item['item_count']);
        $this->assertSame('300.00', $item['totals']['gross']);
        $this->assertSame('80.00', $item['totals']['replacement_deductions']);
        $this->assertSame('35.00', $item['totals']['other_deductions']);
        $this->assertSame('185.00', $item['totals']['net']);

        // Regenerating rebuilds the draft without duplicating anything.
        $this->generate($token)->assertCreated();
        $this->assertSame(1, PayrollItem::query()->count());
        $this->assertSame(3, PayrollItemDeduction::query()->count());
        $this->assertSame(1, PayrollRun::query()->count());
    }

    public function test_mid_month_start_uses_calendar_day_proration(): void
    {
        [, $token] = $this->superAdminToken();
        $this->permanentWith(300, '2026-09-11'); // 20 of 30 days

        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.gross', '200.00');
        $this->generate($token, '2026-10')->assertCreated()->assertJsonPath('data.totals.gross', '300.00');
    }

    public function test_the_october_example_gives_203_23(): void
    {
        [, $token] = $this->superAdminToken();
        $this->permanentWith(300, '2026-10-11');

        $response = $this->generate($token, '2026-10')->assertCreated();
        $this->assertSame('203.23', $response->json('data.totals.gross'));
    }

    public function test_salary_history_splits_the_month(): void
    {
        [, $token] = $this->superAdminToken();
        [, $assignment] = $this->permanentWith(300);
        $assignment->salaryHistories()->create(['effective_from' => '2026-09-16', 'amount' => 400, 'currency' => 'USD', 'frequency' => 'monthly']);

        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.gross', '350.00'); // (15x300 + 15x400) / 30
    }

    public function test_an_unapproved_replacement_is_listed_but_not_deducted(): void
    {
        [, $token] = $this->superAdminToken();
        [, $assignment] = $this->permanentWith(300);
        $this->cover($assignment, range(1, 5), approved: false);

        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.replacement_deductions', '0.00');
        $snapshot = PayrollItem::query()->firstOrFail()->calc_snapshot;
        $this->assertCount(1, $snapshot['pending_replacements_not_deducted']);
        $this->assertSame(5000, $snapshot['pending_replacements_not_deducted'][0]['amount_cents']);
    }

    public function test_unverified_days_are_never_deducted(): void
    {
        [, $token] = $this->superAdminToken();
        [, $assignment] = $this->permanentWith(300);
        $this->cover($assignment, range(1, 5), verified: false);

        $this->generate($token)->assertCreated()->assertJsonPath('data.totals.replacement_deductions', '0.00');
    }

    public function test_a_temporary_employees_own_assignment_gets_no_payroll_line(): void
    {
        [, $token] = $this->superAdminToken();
        $temp = $this->makeEmployee('active', 'temporary');
        $this->makeAssignment($temp, $this->makeCompany(), null, 100, '2026-01-01');

        $response = $this->generate($token)->assertCreated();
        $this->assertSame(0, $response->json('data.item_count'));
        $this->assertCount(1, $response->json('data.warnings'));
    }

    public function test_penalties_are_charged_once_even_when_an_employee_has_two_assignments(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('active', 'permanent');
        $this->makeAssignment($employee, $this->makeCompany('Co A'), null, 300, '2026-01-01');
        $this->makeAssignment($employee, $this->makeCompany('Co B'), null, 100, '2026-01-01');
        EmployeePenalty::query()->create(['employee_id' => $employee->id, 'penalty_date' => '2026-09-05', 'reason' => 'Late', 'deduction_amount' => 15, 'currency' => 'USD', 'payroll_period' => self::P]);

        $this->generate($token)->assertCreated()->assertJsonPath('data.item_count', 2)->assertJsonPath('data.totals.other_deductions', '15.00');
    }

    public function test_absence_is_deducted_for_every_assignment_per_the_approved_spec(): void
    {
        [, $token] = $this->superAdminToken();
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();
        $officer = $this->makeEmployee('active', 'permanent');
        $this->makeAssignment($officer, null, $office, 300, '2026-01-01');
        [$clientStaff] = $this->permanentWith(300);
        foreach ([$officer, $clientStaff] as $e) {
            EmployeeAttendance::query()->create(['employee_id' => $e->id, 'date' => '2026-09-08', 'status' => 'absent']);
        }

        $response = $this->generate($token)->assertCreated();
        $this->assertSame('20.00', $response->json('data.totals.other_deductions')); // 300/30 x 1 day each, office and client alike
    }

    public function test_an_absent_day_is_charged_once_even_with_two_assignments_and_never_outside_the_assignment_dates(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('active', 'permanent');
        $first = $this->makeAssignment($employee, $this->makeCompany('Co A'), null, 300, '2026-01-01');
        $this->makeAssignment($employee, $this->makeCompany('Co B'), null, 300, '2026-01-01');
        $first->update(['end_date' => '2026-09-20', 'status' => 'ended']);
        foreach (['2026-09-08', '2026-09-25'] as $date) {
            EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => $date, 'status' => 'absent']);
        }

        $response = $this->generate($token)->assertCreated();

        // 09-08 is charged once, to the first assignment; 09-25 is after the first assignment ended so only the second can carry it.
        $this->assertSame('20.00', $response->json('data.totals.other_deductions'));
        $this->assertSame(2, PayrollItemDeduction::query()->where('kind', 'absence')->count());
    }

    public function test_a_non_usd_penalty_is_reported_and_not_converted(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee] = $this->permanentWith(300);
        EmployeePenalty::query()->create(['employee_id' => $employee->id, 'penalty_date' => '2026-09-05', 'reason' => 'x', 'deduction_amount' => 50, 'currency' => 'SOS', 'payroll_period' => self::P]);

        $response = $this->generate($token)->assertCreated();
        $this->assertSame('0.00', $response->json('data.totals.other_deductions'));
        $this->assertStringContainsString('not USD', $response->json('data.warnings.0'));
    }

    public function test_the_deduction_source_can_only_be_applied_once_for_a_period_in_the_database(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee] = $this->permanentWith(300);
        $penalty = EmployeePenalty::query()->create(['employee_id' => $employee->id, 'penalty_date' => '2026-09-05', 'reason' => 'x', 'deduction_amount' => 5, 'currency' => 'USD', 'payroll_period' => self::P]);
        $this->generate($token)->assertCreated();
        $item = PayrollItem::query()->firstOrFail();

        $this->expectException(QueryException::class);
        PayrollItemDeduction::query()->create(['payroll_item_id' => $item->id, 'period' => self::P, 'kind' => 'penalty', 'source_type' => 'employee_penalty', 'source_id' => $penalty->id, 'amount_cents' => 500, 'description' => 'duplicate']);
    }

    public function test_full_lifecycle_review_approve_finalize_and_lock(): void
    {
        [, $token] = $this->superAdminToken();
        $this->permanentWith(300);
        $run = $this->generate($token)->assertCreated()->json('data.id');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/approve")->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_WRONG_STATE');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/review")->assertOk()->assertJsonPath('data.status', 'reviewed');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $this->generate($token)->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_LOCKED');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/finalize")->assertOk()->assertJsonPath('data.status', 'finalized');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/reopen", ['reason' => 'Trying to undo a final month'])
            ->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_NOT_REOPENABLE');
        $this->generate($token)->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_LOCKED');
    }

    public function test_an_empty_run_cannot_be_reviewed(): void
    {
        [, $token] = $this->superAdminToken();
        $run = $this->generate($token)->assertCreated()->json('data.id');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/review")->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_EMPTY');
    }

    public function test_an_approved_run_can_be_reopened_with_a_reason_and_regenerated(): void
    {
        [, $token] = $this->superAdminToken();
        $this->permanentWith(300);
        $run = $this->generate($token)->json('data.id');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/review");
        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/approve");

        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/reopen", ['reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/reopen", ['reason' => 'A workday was entered wrongly'])
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $this->generate($token)->assertCreated();
    }

    public function test_the_reviewer_cannot_approve_their_own_review_unless_super_admin(): void
    {
        $this->permanentWith(300);
        $both = $this->tokenWithOnly(['hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve']);
        $run = $this->generate($both)->assertCreated()->json('data.id');
        $this->withToken($both)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/review")->assertOk();

        $this->withToken($both)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/approve")
            ->assertStatus(422)->assertJsonPath('error_code', 'APPROVER_MUST_DIFFER_FROM_REVIEWER');
    }

    public function test_permissions_separate_viewing_preparing_and_approving(): void
    {
        $this->permanentWith(300);
        $run = PayrollRun::query()->create(['period' => self::P, 'status' => 'draft']);

        $hrOnly = $this->tokenWithOnly(['hr.view', 'hr.manage']);
        $this->withToken($hrOnly)->getJson('/api/v1/admin/hr/payroll-runs')->assertForbidden();
        $this->withToken($hrOnly)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => self::P])->assertForbidden();
    }

    public function test_a_payroll_viewer_cannot_prepare_or_approve(): void
    {
        $run = PayrollRun::query()->create(['period' => self::P, 'status' => 'draft']);
        $viewer = $this->tokenWithOnly(['hr.payroll.view']);

        $this->withToken($viewer)->getJson('/api/v1/admin/hr/payroll-runs')->assertOk();
        $this->withToken($viewer)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-08'])->assertForbidden();
        $this->withToken($viewer)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/approve")->assertForbidden();
    }

    public function test_adjustments_need_a_finalized_month_and_apply_in_a_later_run_once(): void
    {
        [, $token] = $this->superAdminToken();
        $this->permanentWith(300);
        $run = $this->generate($token)->json('data.id');
        $itemId = PayrollItem::query()->firstOrFail()->id;
        $body = ['kind' => 'correction', 'amount' => '10.00', 'reason' => 'Missed bonus for September', 'applies_in_period' => '2026-10'];

        $this->withToken($token)->postJson("/api/v1/admin/hr/payroll-items/{$itemId}/adjustments", $body)
            ->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_NOT_FINALIZED');

        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/review");
        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/approve");
        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/finalize");

        $this->withToken($token)->postJson("/api/v1/admin/hr/payroll-items/{$itemId}/adjustments", [...$body, 'applies_in_period' => '2026-09'])
            ->assertStatus(422)->assertJsonPath('error_code', 'ADJUSTMENT_PERIOD_NOT_LATER');
        $this->withToken($token)->postJson("/api/v1/admin/hr/payroll-items/{$itemId}/adjustments", $body)->assertCreated();

        $october = $this->generate($token, '2026-10')->assertCreated();
        $this->assertSame('310.00', $october->json('data.totals.net'));
        $this->generate($token, '2026-10')->assertCreated()->assertJsonPath('data.totals.net', '310.00'); // applied once, not twice
        $this->assertDatabaseHas('payroll_adjustments', ['payroll_item_id' => $itemId, 'status' => 'applied']);
    }

    public function test_a_reversal_cancels_the_net_pay_once(): void
    {
        [, $token] = $this->superAdminToken();
        $this->permanentWith(300);
        $run = $this->generate($token)->json('data.id');
        foreach (['review', 'approve', 'finalize'] as $step) {
            $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/{$step}")->assertOk();
        }
        $itemId = PayrollItem::query()->firstOrFail()->id;
        $body = ['kind' => 'reversal', 'reason' => 'Paid in error, recover next month', 'applies_in_period' => '2026-10'];

        $this->withToken($token)->postJson("/api/v1/admin/hr/payroll-items/{$itemId}/adjustments", $body)->assertCreated();
        $this->withToken($token)->postJson("/api/v1/admin/hr/payroll-items/{$itemId}/adjustments", $body)
            ->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_ITEM_ALREADY_REVERSED');
        $this->generate($token, '2026-10')->assertCreated()->assertJsonPath('data.totals.net', '0.00'); // 300 salary - 300 recovered
    }

    public function test_paying_a_line_writes_the_ledger_once_and_only_after_finalization(): void
    {
        [, $token] = $this->superAdminToken();
        [$employee] = $this->permanentWith(300);
        $run = $this->generate($token)->json('data.id');
        $itemId = PayrollItem::query()->firstOrFail()->id;

        $this->withToken($token)->postJson("/api/v1/admin/hr/payroll-items/{$itemId}/pay", [])->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_NOT_FINALIZED');

        foreach (['review', 'approve', 'finalize'] as $step) {
            $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/{$step}")->assertOk();
        }

        $this->withToken($token)->postJson("/api/v1/admin/hr/payroll-items/{$itemId}/pay", [])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $payment = EmployeePayment::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('300.00', (string) $payment->amount);
        $this->assertSame($itemId, $payment->payroll_item_id);

        $this->withToken($token)->postJson("/api/v1/admin/hr/payroll-items/{$itemId}/pay", [])->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_ITEM_ALREADY_PAID');
        $this->assertSame(1, EmployeePayment::query()->count());
    }

    public function test_excel_export_comes_from_stored_data_with_temporaries_under_their_permanent(): void
    {
        [, $token] = $this->superAdminToken();
        [, $assignment] = $this->permanentWith(300);
        $first = $this->cover($assignment, range(3, 6));
        $second = $this->cover($assignment, range(11, 14));
        $second->update(['start_date' => '2026-09-11']);
        $this->permanentWith(200);
        $run = $this->generate($token)->json('data.id');

        $response = $this->withToken($token)->get("/api/v1/admin/hr/payroll-runs/{$run}/export")->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();

        $types = [];
        for ($r = 4; $r <= 7; $r++) {
            $types[] = (string) $sheet->getCell("C{$r}")->getValue();
        }
        $this->assertSame('Permanent', $types[0] === 'Permanent' ? 'Permanent' : $types[1]);
        $permanentRows = array_keys(array_filter($types, fn ($t) => $t === 'Permanent'));
        $childRows = array_keys(array_filter($types, fn ($t) => str_starts_with($t, 'Temporary')));
        $this->assertCount(2, $permanentRows);
        $this->assertCount(2, $childRows);
        // The two Temporary rows sit directly beneath the same Permanent row, ordered by start date.
        $parentRow = min(...array_map(fn ($i) => $i + 4, $childRows)) - 1;
        $this->assertSame('Permanent', (string) $sheet->getCell("C{$parentRow}")->getValue());
        $this->assertStringContainsString($first->replacementEmployee->full_name, trim((string) $sheet->getCell('B'.($parentRow + 1))->getValue()));
        $this->assertStringContainsString($second->replacementEmployee->full_name, trim((string) $sheet->getCell('B'.($parentRow + 2))->getValue()));
    }
}
