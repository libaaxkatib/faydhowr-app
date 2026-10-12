<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Support\Hr\EmployeeTransitionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Step 4 transition guard: the lifecycle is enforced on the backend, a Super Admin may override it
 * only with a written reason, and every override is recorded with actor, time, employee and the
 * previous and new status.
 */
class EmployeeTransitionGuardTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private function url(Employee $employee): string
    {
        return "/api/v1/admin/hr/employees/{$employee->id}/status";
    }

    private function hrToken(): string
    {
        return $this->tokenWithOnly(['hr.view', 'hr.manage']);
    }

    /** @return array<string, array{string, string}> */
    public static function normalSteps(): array
    {
        return [
            'applicant to recruitment' => ['applicant', 'recruitment'],
            'recruitment to practical' => ['recruitment', 'practical'],
            'practical to waiting' => ['practical', 'waiting'],
            'waiting to approved' => ['waiting', 'approved'],
            'waiting to active' => ['waiting', 'active'],
            'approved to active' => ['approved', 'active'],
        ];
    }

    /** @return array<string, array{string, string}> */
    public static function invalidSteps(): array
    {
        return [
            'applicant straight to active' => ['applicant', 'active'],
            'applicant to practical (skips recruitment)' => ['applicant', 'practical'],
            'applicant to waiting (only a Practical approval does this)' => ['applicant', 'waiting'],
            'waiting back to applicant' => ['waiting', 'applicant'],
            'active back to waiting' => ['active', 'waiting'],
            'active back to applicant' => ['active', 'applicant'],
            'inactive to waiting' => ['inactive', 'waiting'],
            'inactive to active (rehire workflow only)' => ['inactive', 'active'],
            'active to inactive (separation workflow only)' => ['active', 'inactive'],
            'applicant to inactive (separation workflow only)' => ['applicant', 'inactive'],
        ];
    }

    #[DataProvider('normalSteps')]
    public function test_a_normal_lifecycle_step_is_allowed_for_an_hr_manager_without_any_override(string $from, string $to): void
    {
        $employee = $this->makeEmployee($from);

        $this->withToken($this->hrToken())->patchJson($this->url($employee), ['status' => $to])->assertOk();

        $this->assertSame($to, $employee->refresh()->status->value);
        $history = EmployeeStatusHistory::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
        $this->assertNull($history->override_reason);
        $this->assertSame($from, $history->from_status->value);
        $this->assertSame($to, $history->to_status->value);
    }

    #[DataProvider('invalidSteps')]
    public function test_an_invalid_step_is_refused_on_the_backend_and_changes_nothing(string $from, string $to): void
    {
        $employee = $this->makeEmployee($from);

        $this->withToken($this->hrToken())->patchJson($this->url($employee), ['status' => $to])
            ->assertStatus(422)->assertJsonPath('error_code', 'INVALID_STATUS_TRANSITION');

        $this->assertSame($from, $employee->refresh()->status->value);
        $this->assertSame(0, EmployeeStatusHistory::query()->where('employee_id', $employee->id)->count());
    }

    #[DataProvider('invalidSteps')]
    public function test_a_super_admin_is_also_refused_without_an_override_reason(string $from, string $to): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee($from);

        $this->withToken($token)->patchJson($this->url($employee), ['status' => $to])
            ->assertStatus(422)->assertJsonPath('error_code', 'TRANSITION_OVERRIDE_REASON_REQUIRED');

        $this->assertSame($from, $employee->refresh()->status->value);
    }

    public function test_an_hr_manager_cannot_override_even_with_a_reason(): void
    {
        $employee = $this->makeEmployee('applicant');

        $this->withToken($this->hrToken())
            ->patchJson($this->url($employee), ['status' => 'active', 'override_reason' => 'I would like to skip the pipeline please.'])
            ->assertForbidden()->assertJsonPath('error_code', 'TRANSITION_OVERRIDE_FORBIDDEN');

        $this->assertSame('applicant', $employee->refresh()->status->value);
        $this->assertSame(0, EmployeeStatusHistory::query()->count());
    }

    public function test_a_super_admin_override_needs_a_reason_of_at_least_ten_characters(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('applicant');

        foreach (['', '   ', 'too short'] as $reason) {
            $this->withToken($token)->patchJson($this->url($employee), ['status' => 'active', 'override_reason' => $reason])
                ->assertStatus(422)->assertJsonPath('error_code', 'TRANSITION_OVERRIDE_REASON_REQUIRED');
        }

        $this->assertSame('applicant', $employee->refresh()->status->value);
    }

    public function test_a_super_admin_override_with_a_reason_works_and_is_fully_recorded(): void
    {
        [$admin, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('applicant');

        $this->withToken($token)
            ->patchJson($this->url($employee), ['status' => 'active', 'note' => 'Per plan', 'override_reason' => '  Completed all required processes,   confirmed by the owner. '])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame('active', $employee->refresh()->status->value);

        $history = EmployeeStatusHistory::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('applicant', $history->from_status->value);
        $this->assertSame('active', $history->to_status->value);
        $this->assertSame($admin->id, $history->changed_by);
        $this->assertSame('Completed all required processes, confirmed by the owner.', $history->override_reason);
        $this->assertSame('override', $history->source);
        $this->assertNotNull($history->created_at);

        $audit = DB::table('audit_logs')->where('entity_type', Employee::class)->where('entity_id', $employee->id)->where('description', 'like', '%override%')->first();
        $this->assertNotNull($audit, 'The override is in the audit log.');
        $this->assertSame($admin->id, (int) $audit->admin_id);
        $this->assertNotNull($audit->created_at);
        $metadata = json_decode($audit->metadata, true);
        $this->assertSame(['from_status' => 'applicant', 'to_status' => 'active', 'override' => true, 'override_reason' => $history->override_reason], $metadata);
    }

    public function test_the_response_exposes_the_override_on_the_status_history(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('applicant');

        $this->withToken($token)
            ->patchJson($this->url($employee), ['status' => 'active', 'override_reason' => 'Confirmed complete by the owner.'])->assertOk();

        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.status_histories.0.is_override', true)
            ->assertJsonPath('data.status_histories.0.override_reason', 'Confirmed complete by the owner.');
    }

    public function test_a_normal_step_by_a_super_admin_records_no_override_even_if_a_reason_is_sent(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('waiting');

        $this->withToken($token)->patchJson($this->url($employee), ['status' => 'active', 'override_reason' => 'Not actually needed here.'])->assertOk();

        $history = EmployeeStatusHistory::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertNull($history->override_reason);
        $this->assertNotSame('override', $history->source);
    }

    public function test_changing_to_the_current_status_is_refused_even_for_a_super_admin_with_a_reason(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('waiting');

        $this->withToken($token)->patchJson($this->url($employee), ['status' => 'waiting', 'override_reason' => 'Trying to rewrite history here.'])
            ->assertStatus(422)->assertJsonPath('error_code', 'NO_STATUS_CHANGE');

        $this->assertSame(0, EmployeeStatusHistory::query()->count());
    }

    public function test_a_repeated_request_cannot_apply_the_same_step_twice(): void
    {
        $employee = $this->makeEmployee('waiting');
        $token = $this->hrToken();

        $this->withToken($token)->patchJson($this->url($employee), ['status' => 'active'])->assertOk();
        $this->withToken($token)->patchJson($this->url($employee), ['status' => 'active'])->assertStatus(422)->assertJsonPath('error_code', 'NO_STATUS_CHANGE');

        $this->assertSame(1, EmployeeStatusHistory::query()->where('employee_id', $employee->id)->count());
    }

    public function test_the_guard_matrix_per_workflow(): void
    {
        $guard = new EmployeeTransitionGuard;
        $labels = fn (array $statuses): array => array_map(fn (EmployeeStatus $s): string => $s->value, $statuses);

        $this->assertSame(['recruitment'], $labels($guard->normalNext(EmployeeStatus::Applicant)));
        $this->assertSame(['approved', 'active'], $labels($guard->normalNext(EmployeeStatus::Waiting)));
        $this->assertSame([], $labels($guard->normalNext(EmployeeStatus::Active)));
        $this->assertSame([], $labels($guard->normalNext(EmployeeStatus::Inactive)));

        $this->assertSame(['inactive'], $labels($guard->normalNext(EmployeeStatus::Active, EmployeeTransitionGuard::CONTEXT_SEPARATION)));
        $this->assertSame([], $labels($guard->normalNext(EmployeeStatus::Inactive, EmployeeTransitionGuard::CONTEXT_SEPARATION)));
        $this->assertSame(['active'], $labels($guard->normalNext(EmployeeStatus::Inactive, EmployeeTransitionGuard::CONTEXT_REHIRE)));
        $this->assertSame([], $labels($guard->normalNext(EmployeeStatus::Waiting, EmployeeTransitionGuard::CONTEXT_REHIRE)));
        $this->assertSame(['waiting'], $labels($guard->normalNext(EmployeeStatus::Applicant, EmployeeTransitionGuard::CONTEXT_PRACTICAL_APPROVAL)));
        $this->assertSame([], $labels($guard->normalNext(EmployeeStatus::Active, EmployeeTransitionGuard::CONTEXT_PRACTICAL_APPROVAL)));
        $this->assertSame(['waiting'], $labels($guard->normalNext(EmployeeStatus::Practical, EmployeeTransitionGuard::CONTEXT_WAITING_IMPORT)));
        $this->assertSame([], $labels($guard->normalNext(EmployeeStatus::Active, EmployeeTransitionGuard::CONTEXT_WAITING_IMPORT)));
        $this->assertSame([], $labels($guard->normalNext(EmployeeStatus::Inactive, EmployeeTransitionGuard::CONTEXT_WAITING_IMPORT)));

        $this->assertSame(EmployeeTransitionGuard::DECISION_NO_CHANGE, $guard->evaluate(EmployeeStatus::Active, EmployeeStatus::Active));
        $this->assertSame(EmployeeTransitionGuard::DECISION_OVERRIDE_REQUIRED, $guard->evaluate(EmployeeStatus::Applicant, EmployeeStatus::Active));
        $this->assertSame(EmployeeTransitionGuard::DECISION_NORMAL, $guard->evaluate(EmployeeStatus::Waiting, EmployeeStatus::Active));
    }

    public function test_separation_and_rehire_are_unchanged_normal_workflows(): void
    {
        $hr = $this->hrToken();
        $employee = $this->makeEmployee('active');

        $this->withToken($hr)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/separate", [
            'reason' => 'resigned', 'separation_date' => '2026-10-01', 'rehire_eligible' => true,
        ])->assertOk();
        $this->assertSame('inactive', $employee->refresh()->status->value);

        $this->withToken($hr)->patchJson("/api/v1/admin/hr/employees/{$employee->id}/rehire", [])->assertOk();
        $this->assertSame('active', $employee->refresh()->status->value);
    }
}
