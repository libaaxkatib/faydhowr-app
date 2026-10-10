<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\EmployeeStatusHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

class BulkActivateEmployeesTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/hr/employees/bulk-activate';

    private function body(array $employees, array $extra = []): array
    {
        return array_merge([
            'records' => array_map(fn (Employee $e, int $i) => ['employee_id' => $e->id, 'source_ref' => 'workbook row '.($i + 2)], $employees, array_keys($employees)),
            'note' => 'Approved as working in the company workbook',
        ], $extra);
    }

    public function test_it_is_a_dry_run_by_default_and_changes_nothing(): void
    {
        [, $token] = $this->superAdminToken();
        $waiting = $this->makeEmployee('waiting');

        $this->withToken($token)->postJson(self::URL, $this->body([$waiting]))
            ->assertOk()->assertJsonPath('data.dry_run', true)->assertJsonPath('data.summary.would_activate', 1);

        $this->assertSame('waiting', $waiting->refresh()->status->value);
        $this->assertSame(0, EmployeeStatusHistory::query()->count());
    }

    public function test_a_real_run_needs_explicit_confirmation(): void
    {
        [, $token] = $this->superAdminToken();
        $waiting = $this->makeEmployee('waiting');

        $this->withToken($token)->postJson(self::URL, $this->body([$waiting], ['dry_run' => false]))
            ->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->assertSame('waiting', $waiting->refresh()->status->value);
    }

    public function test_each_employee_is_activated_through_the_workflow_with_its_own_history_and_audit(): void
    {
        [, $token] = $this->superAdminToken();
        $waiting = $this->makeEmployee('waiting');
        $waiting->update(['waiting_since' => now()->subDays(5)]);
        $applicant = $this->makeEmployee('applicant');
        $applicant->update(['pipeline_stage' => 'need_training']);

        $this->withToken($token)->postJson(self::URL, $this->body([$waiting, $applicant], ['dry_run' => false, 'confirm' => true, 'joining_date' => '2026-10-01']))
            ->assertOk()->assertJsonPath('data.summary.activated', 2)->assertJsonPath('data.summary.failed', 0);

        foreach ([$waiting, $applicant] as $e) {
            $e->refresh();
            $this->assertSame('active', $e->status->value);
            $this->assertSame('2026-10-01', $e->joining_date->toDateString());
            $history = EmployeeStatusHistory::query()->where('employee_id', $e->id)->latest('id')->first();
            $this->assertSame('active', $history->to_status->value);
            $this->assertStringContainsString('Approved as working', $history->note);
            $this->assertStringContainsString('workbook row', $history->note);
            $this->assertSame(1, DB::table('audit_logs')->where('entity_id', $e->id)->where('description', 'like', '%status changed%')->count());
        }

        $this->assertNull($waiting->waiting_since, 'Leaving Waiting clears the waiting clock.');
        $this->assertSame('need_training', $applicant->pipeline_stage->value, 'No completion is invented: the pipeline stage is untouched.');
        $this->assertDatabaseCount('employee_historical_completions', 0);
    }

    public function test_an_existing_joining_date_is_never_overwritten(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('waiting');
        $employee->update(['joining_date' => '2025-03-01']);

        $this->withToken($token)->postJson(self::URL, $this->body([$employee], ['dry_run' => false, 'confirm' => true, 'joining_date' => '2026-10-01']))->assertOk();

        $this->assertSame('2025-03-01', $employee->refresh()->joining_date->toDateString());
    }

    public function test_an_inactive_employee_is_reactivated_through_rehire_and_keeps_every_separation(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('inactive');
        EmployeeSeparation::query()->create(['employee_id' => $employee->id, 'reason' => 'other', 'separation_date' => '2026-08-01', 'rehire_eligible' => true, 'notes' => 'original cancellation']);

        $this->withToken($token)->postJson(self::URL, $this->body([$employee], ['dry_run' => false, 'confirm' => true]))
            ->assertOk()->assertJsonPath('data.results.0.outcome', 'activated');

        $this->assertSame('active', $employee->refresh()->status->value);
        $this->assertSame(1, EmployeeSeparation::query()->where('employee_id', $employee->id)->count());
        $this->assertSame('original cancellation', EmployeeSeparation::query()->where('employee_id', $employee->id)->value('notes'));
        $history = EmployeeStatusHistory::query()->where('employee_id', $employee->id)->latest('id')->first();
        $this->assertSame('inactive', $history->from_status->value);
        $this->assertSame('active', $history->to_status->value);
    }

    public function test_one_failure_never_blocks_or_rolls_back_the_others(): void
    {
        [, $token] = $this->superAdminToken();
        $good = $this->makeEmployee('waiting');
        $archived = $this->makeEmployee('waiting');
        $archived->delete();
        $already = $this->makeEmployee('active');
        $mismatch = $this->makeEmployee('waiting');
        $drifted = $this->makeEmployee('applicant');

        $payload = [
            'records' => [
                ['employee_id' => $archived->id],
                ['employee_id' => 999999],
                ['employee_id' => $mismatch->id, 'expected_employee_number' => 'EMP-000000'],
                ['employee_id' => $drifted->id, 'expected_status' => 'waiting'],
                ['employee_id' => $already->id],
                ['employee_id' => $good->id, 'expected_employee_number' => $good->employee_number, 'expected_status' => 'waiting'],
            ],
            'note' => 'Approved as working in the company workbook', 'dry_run' => false, 'confirm' => true,
        ];

        $response = $this->withToken($token)->postJson(self::URL, $payload)->assertOk();
        $codes = array_column($response->json('data.results'), 'code');

        $this->assertSame(['EMPLOYEE_ARCHIVED', 'EMPLOYEE_NOT_FOUND', 'IDENTITY_MISMATCH', 'STATUS_CHANGED_SINCE_PLAN', null, null], $codes);
        $this->assertSame(1, $response->json('data.summary.activated'));
        $this->assertSame(1, $response->json('data.summary.already_active'));
        $this->assertSame(4, $response->json('data.summary.failed'));
        $this->assertSame('active', $good->refresh()->status->value);
        $this->assertSame('waiting', $mismatch->refresh()->status->value);
        $this->assertSame('applicant', $drifted->refresh()->status->value);
    }

    public function test_hr_view_alone_cannot_use_it_and_the_batch_size_is_capped(): void
    {
        $employee = $this->makeEmployee('waiting');
        $this->withToken($this->tokenWithOnly(['hr.view']))->postJson(self::URL, $this->body([$employee]))->assertForbidden();
    }

    public function test_more_than_the_maximum_batch_is_rejected(): void
    {
        [, $token] = $this->superAdminToken();
        $records = array_map(fn (int $i) => ['employee_id' => $i], range(1, 301));

        $this->withToken($token)->postJson(self::URL, ['records' => $records, 'note' => 'Approved as working in the company workbook'])
            ->assertStatus(422)->assertJsonValidationErrors('records');
    }
}
