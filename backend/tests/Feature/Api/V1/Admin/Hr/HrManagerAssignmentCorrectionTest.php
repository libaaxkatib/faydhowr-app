<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Models\PayrollWorkplacePayment;
use App\Models\Position;
use App\Support\Hr\Payroll\CorrectionAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FinalizesCompanies;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision 2026-11-04: an HR Manager may add an employee to a company even when the payroll month it reaches is approved or finalized. It is NOT a
 * general Super Admin privilege: only this one operation ('assigned'). The closed month is never rewritten - the financial effect is recorded as a
 * traceable PayrollCorrection (named after the HR Manager who did it, with the reason), exactly as for a Super Admin; paying an underpayment, editing,
 * stopping, replacing or returning an assignment and every other correction stay Super Admin only; other roles stay blocked. Synthetic data only.
 *
 * September 2026 is finalized with one employee at 300.00. A NEW employee added from 10 September (21 of 30 days) would be owed 210.00 for it.
 */
class HrManagerAssignmentCorrectionTest extends TestCase
{
    use FinalizesCompanies;
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $company;

    private Position $position;

    private EmployeeWorkAssignment $existing;

    private Employee $newcomer;

    private Admin $superAdmin;

    private string $super;

    private string $hrManager;

    private int $runId;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-12-20 10:00:00');
        [$this->superAdmin, $this->super] = $this->superAdminToken();
        $this->hrManager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.payroll.view']);
        $this->company = $this->makeCompany('Acme Co');
        $this->position = Position::query()->create(['name' => 'Supervisor']);
        $this->existing = $this->makeAssignment($this->makeEmployee(), $this->company, null, 300, '2026-01-01');
        $this->newcomer = $this->makeEmployee();

        $this->runId = (int) $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated()->json('data.id');
        $this->finalizeEveryCompany($this->runId, $this->super);
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

    private function add(string $token, array $over = [])
    {
        return $this->as($token)->postJson("/api/v1/admin/hr/client-companies/{$this->company->id}/employees", array_replace([
            'employee_id' => $this->newcomer->id, 'assignment_type' => 'permanent', 'position_id' => $this->position->id,
            'start_date' => '2026-09-10', 'salary_amount' => 300, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ], $over));
    }

    /** @return array<string, mixed> everything that must stay exactly as it was when payroll is only READ */
    private function closedSnapshot(): array
    {
        return [
            'run' => [...PayrollRun::query()->findOrFail($this->runId)->only(['status', 'totals', 'finalized_by']), 'finalized_at' => PayrollRun::query()->findOrFail($this->runId)->finalized_at?->toIso8601String()],
            'items' => PayrollItem::query()->orderBy('id')->get(['id', 'gross_cents', 'net_cents', 'employee_id', 'work_assignment_id', 'payment_status'])->toArray(),
            'payments' => PayrollWorkplacePayment::query()->count(),
        ];
    }

    /** @return array{assignments: int, corrections: int, audit: int} */
    private function counts(): array
    {
        return ['assignments' => EmployeeWorkAssignment::query()->count(), 'corrections' => PayrollCorrection::query()->count(), 'audit' => AuditLog::query()->count()];
    }

    // ------------------------------------------------------------------------------------------------ allowed

    public function test_an_hr_manager_can_add_an_employee_to_a_company_whose_payroll_is_finalized_with_a_reason(): void
    {
        $before = $this->closedSnapshot();

        // First attempt without a reason: told what is affected, nothing is saved.
        $counts = $this->counts();
        $this->add($this->hrManager)->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED')->assertJsonPath('errors.affected_periods.0', '2026-09');
        $this->assertSame($counts, $this->counts(), 'refused as a whole: no assignment, no correction, no audit');

        $this->add($this->hrManager, ['correction_reason' => 'The employee started on 10 September and was left out of the file'])->assertCreated();

        $assignment = EmployeeWorkAssignment::query()->where('employee_id', $this->newcomer->id)->sole();
        $this->assertSame([$this->company->id, '2026-09-10'], [$assignment->client_company_id, $assignment->start_date->toDateString()]);
        $this->assertSame($before, $this->closedSnapshot(), 'the finalized run, its lines and every payment are exactly as they were');
    }

    public function test_the_financial_effect_is_a_traceable_correction_naming_the_hr_manager_not_a_rewrite_of_the_closed_month(): void
    {
        $hrManagerId = (int) Admin::query()->where('role', AdminRole::HrManager)->value('id');
        $this->add($this->hrManager, ['correction_reason' => 'Missed registration, started 10 September'])->assertCreated();

        $correction = PayrollCorrection::query()->sole();
        $assignment = EmployeeWorkAssignment::query()->where('employee_id', $this->newcomer->id)->sole();
        $this->assertSame(['2026-09', 'underpayment', 21000, 0, 21000, $hrManagerId, 'assigned', $this->company->id, $this->newcomer->id, $assignment->id], [
            $correction->period, $correction->kind, (int) $correction->outstanding_cents, (int) $correction->recorded_net_cents, (int) $correction->corrected_net_cents, $correction->corrected_by,
            $correction->action, $correction->client_company_id, $correction->employee_id, $correction->work_assignment_id,
        ]);
        $this->assertSame('Missed registration, started 10 September', $correction->reason);
        $this->assertSame($this->position->id, $correction->new_values['position_id']);
        $this->assertSame('2026-09-10', $correction->new_values['start_date']);

        $audit = AuditLog::query()->where('entity_type', PayrollCorrection::class)->where('entity_id', $correction->id)->firstOrFail();
        $this->assertSame([$hrManagerId, 'hr_manager', $hrManagerId, '2026-09', 'payroll_corrected'], [$audit->admin_id, $audit->metadata['corrected_by_role'], $audit->metadata['corrected_by_admin_id'], $audit->metadata['period'], $audit->metadata['event']]);
        $this->assertSame(0, PayrollItem::query()->where('work_assignment_id', $assignment->id)->count(), 'the original September payroll has no line for the newcomer: the difference lives in the correction');
    }

    public function test_a_super_admin_keeps_the_same_ability(): void
    {
        $this->add($this->super, ['correction_reason' => 'Added by the Super Admin'])->assertCreated();

        $correction = PayrollCorrection::query()->sole();
        $this->assertSame([$this->superAdmin->id, 'underpayment', 21000], [$correction->corrected_by, $correction->kind, (int) $correction->outstanding_cents]);
        $this->assertSame('super_admin', AuditLog::query()->where('entity_type', PayrollCorrection::class)->firstOrFail()->metadata['corrected_by_role']);
    }

    public function test_a_change_that_does_not_touch_closed_payroll_needs_no_correction_for_anyone(): void
    {
        $this->add($this->hrManager, ['start_date' => '2026-10-05'])->assertCreated();

        $this->assertSame(0, PayrollCorrection::query()->count());
    }

    // ------------------------------------------------------------------------------------------------ not allowed

    public function test_other_roles_stay_blocked_and_nothing_is_saved(): void
    {
        $counts = $this->counts();
        $editor = $this->tokenWithOnly(['hr.view', 'hr.manage']); // an HR editor who is not the HR Manager
        $marketing = Admin::factory()->create(['role' => AdminRole::MarketingManager])->createToken('t')->plainTextToken;
        $nobody = $this->noPermissionToken();

        $this->add($editor, ['correction_reason' => 'Trying to correct payroll'])->assertForbidden()->assertJsonPath('error_code', 'CLOSED_PAYROLL_CORRECTION_NOT_ALLOWED');
        $this->add($marketing, ['correction_reason' => 'Trying'])->assertForbidden();
        $this->add($nobody, ['correction_reason' => 'Trying'])->assertForbidden();

        $this->assertSame($counts, $this->counts());
    }

    public function test_an_hr_manager_can_correct_other_finalized_payroll_with_a_reason_and_it_is_recorded_as_a_correction(): void
    {
        // Owner decision 2026-11-04 (replaces "Super Admin only"): editing an assignment (its start date reaches September) and changing its salary are
        // corrections an HR Manager may record too - never silently: a reason is mandatory and the closed month is never rewritten.
        $this->as($this->hrManager)->putJson("/api/v1/admin/hr/work-assignments/{$this->existing->id}", ['start_date' => '2026-09-21'])
            ->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->assertSame('2026-01-01', $this->existing->fresh()->start_date->toDateString(), 'refused as a whole without a reason');

        $this->as($this->hrManager)->putJson("/api/v1/admin/hr/work-assignments/{$this->existing->id}", ['start_date' => '2026-09-21', 'correction_reason' => 'The employee really started on 21 September'])->assertOk();
        $this->as($this->hrManager)->putJson("/api/v1/admin/hr/work-assignments/{$this->existing->id}", ['salary_amount' => 900, 'salary_effective_from' => '2026-09-21', 'correction_reason' => 'The salary was agreed for September'])->assertOk();

        $hrManagerId = (int) Admin::query()->where('role', AdminRole::HrManager)->value('id');
        $this->assertGreaterThanOrEqual(1, PayrollCorrection::query()->where('corrected_by', $hrManagerId)->count());
        $this->assertSame('hr_manager', AuditLog::query()->where('entity_type', PayrollCorrection::class)->firstOrFail()->metadata['corrected_by_role']);
        $this->assertSame([true, false], [CorrectionAuthority::matrix()['correct_closed_payroll']['hr_manager'], CorrectionAuthority::matrix()['pay_underpayment_to_employee']['hr_manager']], 'correcting is allowed; paying the underpayment is not');
    }

    public function test_an_hr_manager_cannot_pay_the_underpayment_that_the_assignment_created(): void
    {
        $this->add($this->hrManager, ['correction_reason' => 'Started on 10 September'])->assertCreated();
        $correction = PayrollCorrection::query()->sole();

        $this->as($this->hrManager)->postJson("/api/v1/admin/hr/payroll-corrections/{$correction->id}/transactions", ['type' => 'underpayment_payment', 'amount' => '100.00', 'transaction_date' => '2026-12-20', 'note' => 'Paying it', 'idempotency_key' => 'pay-underpayment-1'])
            ->assertForbidden();
        $this->assertSame(21000, (int) $correction->fresh()->outstanding_cents, 'paying money out of a correction is still a Super Admin decision');
    }

    // ------------------------------------------------------------------------------------------------ failure and duplicates

    public function test_a_failed_operation_leaves_no_partial_assignment_correction_or_audit_record(): void
    {
        $counts = $this->counts();

        $this->add($this->hrManager, ['position_id' => 999999, 'correction_reason' => 'Valid reason here'])->assertStatus(422);
        $this->add($this->hrManager, ['correction_reason' => 'ab'])->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->add($this->hrManager, ['employee_id' => 999999, 'correction_reason' => 'Valid reason here'])->assertStatus(422);

        $this->assertSame($counts, $this->counts());
    }

    public function test_the_same_employee_cannot_be_added_to_the_same_company_twice_even_as_a_correction(): void
    {
        $this->add($this->hrManager, ['correction_reason' => 'Started on 10 September'])->assertCreated();
        $counts = $this->counts();

        $this->add($this->hrManager, ['start_date' => '2026-09-12', 'correction_reason' => 'Added again by mistake'])->assertStatus(422);

        $this->assertSame($counts, $this->counts());
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('employee_id', $this->newcomer->id)->count());
    }

    public function test_the_other_assignment_route_enforces_the_same_narrow_permission_and_the_same_mandatory_reason(): void
    {
        $url = "/api/v1/admin/hr/employees/{$this->newcomer->id}/work-assignments";
        $body = ['client_company_id' => $this->company->id, 'position_id' => $this->position->id, 'assignment_type' => 'permanent', 'start_date' => '2026-09-10', 'salary_amount' => 300, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'];
        $editor = $this->tokenWithOnly(['hr.view', 'hr.manage']);
        $counts = $this->counts();

        // Other roles stay blocked; the HR Manager is refused without a (real) reason - the backend checks it - and nothing is saved.
        $this->as($editor)->postJson($url, [...$body, 'correction_reason' => 'Trying to correct payroll'])->assertForbidden()->assertJsonPath('error_code', 'CLOSED_PAYROLL_CORRECTION_NOT_ALLOWED');
        foreach ([[], ['correction_reason' => ''], ['correction_reason' => '   '], ['correction_reason' => 'ab']] as $reason) {
            $this->as($this->hrManager)->postJson($url, [...$body, ...$reason])->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        }
        $this->assertSame($counts, $this->counts());

        $this->as($this->hrManager)->postJson($url, [...$body, 'correction_reason' => 'Started on 10 September'])->assertCreated();
        $correction = PayrollCorrection::query()->sole();
        $this->assertSame(['assigned', 'Started on 10 September', 21000], [$correction->action, $correction->reason, (int) $correction->outstanding_cents]);
        $this->assertSame((int) Admin::query()->where('role', AdminRole::HrManager)->value('id'), (int) $correction->corrected_by);
    }

    public function test_the_reason_must_be_a_real_one_on_the_company_route_too(): void
    {
        foreach (['', '   ', 'ab', null] as $reason) {
            $this->add($this->hrManager, ['correction_reason' => $reason])->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        }
        $this->assertSame(0, PayrollCorrection::query()->count());
    }

    public function test_existing_payroll_approval_finalization_and_payment_rules_are_intact(): void
    {
        $this->add($this->hrManager, ['correction_reason' => 'Started on 10 September'])->assertCreated();

        // The finalized month still cannot be regenerated or reopened, and a payment is still recorded the normal way.
        $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_LOCKED');
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$this->runId}/reopen", ['reason' => 'Trying to undo a final month'])->assertStatus(422);
        $sectionId = (int) PayrollRunSection::query()->where('payroll_run_id', $this->runId)->value('id');
        $this->approvePaid($sectionId, $this->super)->assertCreated();
        $this->assertSame(30000, (int) PayrollWorkplacePayment::query()->sole()->amount_cents, 'the payment is for the finalized 300.00, not for the correction');
    }
}
