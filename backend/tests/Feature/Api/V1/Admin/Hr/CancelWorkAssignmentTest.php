<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\PayrollRun;
use App\Models\TemporaryReplacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

class CancelWorkAssignmentTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private function cancel(string $token, int $id, ?string $reason = 'Created by mistake from the import')
    {
        return $this->withToken($token)->patchJson("/api/v1/admin/hr/work-assignments/{$id}/cancel", $reason === null ? [] : ['reason' => $reason]);
    }

    public function test_a_mistaken_assignment_is_cancelled_with_a_reason_and_kept_for_history(): void
    {
        [, $token] = $this->superAdminToken();
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->makeCompany());

        $this->cancel($token, $assignment->id)->assertOk()->assertJsonPath('data.status', 'cancelled');

        $assignment->refresh();
        $this->assertSame('cancelled', $assignment->status->value);
        $this->assertStringContainsString('Created by mistake', $assignment->notes);
        $this->assertDatabaseHas('employee_work_assignments', ['id' => $assignment->id]);
        $this->assertSame(1, $assignment->salaryHistories()->count(), 'History is kept.');
    }

    public function test_a_reason_is_required_and_a_second_cancel_is_refused(): void
    {
        [, $token] = $this->superAdminToken();
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->makeCompany());

        $this->cancel($token, $assignment->id, null)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->cancel($token, $assignment->id)->assertOk();
        $this->cancel($token, $assignment->id)->assertStatus(422)->assertJsonPath('error_code', 'WORK_ASSIGNMENT_ALREADY_CANCELLED');
    }

    public function test_after_cancelling_the_employee_can_be_assigned_again_and_payroll_ignores_the_cancelled_one(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $company, null, 300, '2026-01-01');
        $this->cancel($token, $assignment->id)->assertOk();

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$employee->id}/work-assignments", [
            'client_company_id' => $company->id, 'start_date' => '2026-09-01', 'salary_amount' => 200, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])
            ->assertCreated()->assertJsonPath('data.item_count', 1)->assertJsonPath('data.totals.gross', '200.00');
    }

    public function test_it_is_refused_once_the_assignment_has_payroll_or_replacements(): void
    {
        [, $token] = $this->superAdminToken();
        $withPayroll = $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Co One'), null, 300, '2026-01-01');
        $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated();
        $this->assertSame(1, PayrollRun::query()->count());

        $this->cancel($token, $withPayroll->id)->assertStatus(422)->assertJsonPath('error_code', 'WORK_ASSIGNMENT_HAS_PAYROLL');

        $withCover = $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Co Two'), null, 300, '2026-10-01');
        TemporaryReplacement::query()->create([
            'work_assignment_id' => $withCover->id, 'replacement_employee_id' => $this->makeEmployee('active', 'temporary')->id,
            'start_date' => '2026-10-02', 'currency' => 'USD', 'status' => 'active',
        ]);
        $this->cancel($token, $withCover->id)->assertStatus(422)->assertJsonPath('error_code', 'WORK_ASSIGNMENT_HAS_REPLACEMENTS');
    }

    public function test_hr_view_alone_cannot_cancel(): void
    {
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->makeCompany());

        $this->cancel($this->tokenWithOnly(['hr.view']), $assignment->id)->assertForbidden();
        $this->assertSame('active', $assignment->refresh()->status->value);
    }
}
