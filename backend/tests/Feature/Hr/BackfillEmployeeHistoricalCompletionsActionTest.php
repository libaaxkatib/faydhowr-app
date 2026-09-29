<?php

namespace Tests\Feature\Hr;

use App\Actions\Hr\BackfillEmployeeHistoricalCompletionsAction;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeHistoricalCompletion;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeUniform;
use App\Models\TrainingBatchParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #12: the approved historical-completion rule (Green ∪ Waiting List,
 * deduplicated, all 3 stages, "Ready" ignored entirely) — see the migration
 * and BackfillEmployeeHistoricalCompletionsAction docblocks for the full
 * business-rule writeup. This exercises the actual database writes using
 * RefreshDatabase, so nothing here touches a real database.
 */
class BackfillEmployeeHistoricalCompletionsActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(array $overrides = []): Employee
    {
        $category = EmployeeCategory::query()->where('name', 'General Cleaning')->sole();

        return Employee::query()->create(array_merge([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->numerify('6########'),
            'employee_category_id' => $category->id,
            'status' => 'applicant',
            'notes' => '',
        ], $overrides));
    }

    private function markAsGreen(Employee $employee): void
    {
        $employee->update(['notes' => 'Migrated from Excel HR workbook. Other info: Wuu shaqeeyaa ama hore shaqo loo geeyay.']);
    }

    private function markAsWaitingList(Employee $employee): void
    {
        EmployeeStatusHistory::query()->create([
            'employee_id' => $employee->id,
            'from_status' => null,
            'to_status' => $employee->status,
            'note' => 'Migrated from Excel HR workbook (Waiting List:12).',
        ]);
    }

    private function markAsReadyOnly(Employee $employee): void
    {
        // "Ready" leaves no Green/Waiting-List marker at all — just a plain
        // status='waiting' with an ordinary migration note, per the approved
        // rule that Ready is fake/unreliable and must never be used.
        EmployeeStatusHistory::query()->create([
            'employee_id' => $employee->id,
            'from_status' => null,
            'to_status' => $employee->status,
            'note' => 'Migrated from Excel HR workbook (REGISTRATION:5).',
        ]);
    }

    public function test_green_employee_gets_exactly_three_historical_completion_rows(): void
    {
        $employee = $this->makeEmployee();
        $this->markAsGreen($employee);

        app(BackfillEmployeeHistoricalCompletionsAction::class)->backfill();

        $rows = EmployeeHistoricalCompletion::query()->where('employee_id', $employee->id)->get();
        self::assertCount(3, $rows);
        self::assertEqualsCanonicalizing(['training', 'practical', 'uniform'], $rows->pluck('stage.value')->all());
    }

    public function test_waiting_list_employee_gets_exactly_three_historical_completion_rows(): void
    {
        $employee = $this->makeEmployee(['status' => 'waiting']);
        $this->markAsWaitingList($employee);

        app(BackfillEmployeeHistoricalCompletionsAction::class)->backfill();

        self::assertCount(3, EmployeeHistoricalCompletion::query()->where('employee_id', $employee->id)->get());
    }

    public function test_employee_in_both_sources_gets_exactly_three_rows_not_six(): void
    {
        $employee = $this->makeEmployee(['status' => 'waiting']);
        $this->markAsGreen($employee);
        $this->markAsWaitingList($employee);

        app(BackfillEmployeeHistoricalCompletionsAction::class)->backfill();

        $rows = EmployeeHistoricalCompletion::query()->where('employee_id', $employee->id)->get();
        self::assertCount(3, $rows);
        self::assertStringContainsString('Green + Waiting List', $rows->first()->source_reference);
    }

    public function test_ready_only_employee_gets_zero_historical_completion_rows(): void
    {
        $employee = $this->makeEmployee(['status' => 'waiting']);
        $this->markAsReadyOnly($employee);

        app(BackfillEmployeeHistoricalCompletionsAction::class)->backfill();

        self::assertCount(0, EmployeeHistoricalCompletion::query()->where('employee_id', $employee->id)->get());
    }

    public function test_backfill_does_not_change_employee_status_or_pipeline_stage(): void
    {
        $employee = $this->makeEmployee(['status' => 'applicant', 'pipeline_stage' => 'need_training']);
        $this->markAsGreen($employee);

        app(BackfillEmployeeHistoricalCompletionsAction::class)->backfill();

        $employee->refresh();
        self::assertSame('applicant', $employee->status->value);
        self::assertSame('need_training', $employee->pipeline_stage->value);
    }

    public function test_backfill_does_not_move_anyone_between_live_queues(): void
    {
        $needTraining = $this->makeEmployee(['pipeline_stage' => 'need_training']);
        $this->markAsGreen($needTraining);

        app(BackfillEmployeeHistoricalCompletionsAction::class)->backfill();

        $needTraining->refresh();
        // Still sitting in its original live pipeline_stage — historical
        // completion never alters queue membership.
        self::assertSame('need_training', $needTraining->pipeline_stage->value);
    }

    public function test_backfill_does_not_create_fake_training_batch_participant_records(): void
    {
        $employee = $this->makeEmployee();
        $this->markAsGreen($employee);

        app(BackfillEmployeeHistoricalCompletionsAction::class)->backfill();

        self::assertSame(0, TrainingBatchParticipant::query()->where('employee_id', $employee->id)->count());
    }

    public function test_backfill_does_not_create_fake_uniform_records(): void
    {
        $employee = $this->makeEmployee();
        $this->markAsGreen($employee);

        app(BackfillEmployeeHistoricalCompletionsAction::class)->backfill();

        self::assertSame(0, EmployeeUniform::query()->where('employee_id', $employee->id)->count());
    }

    public function test_backfill_is_idempotent_when_run_twice(): void
    {
        $employee = $this->makeEmployee();
        $this->markAsGreen($employee);

        app(BackfillEmployeeHistoricalCompletionsAction::class)->backfill();
        app(BackfillEmployeeHistoricalCompletionsAction::class)->backfill();

        self::assertCount(3, EmployeeHistoricalCompletion::query()->where('employee_id', $employee->id)->get());
    }

    public function test_analyze_reports_exact_counts_without_writing_anything(): void
    {
        $green = $this->makeEmployee();
        $this->markAsGreen($green);
        $waiting = $this->makeEmployee(['status' => 'waiting']);
        $this->markAsWaitingList($waiting);
        $both = $this->makeEmployee(['status' => 'waiting']);
        $this->markAsGreen($both);
        $this->markAsWaitingList($both);
        $readyOnly = $this->makeEmployee(['status' => 'waiting']);
        $this->markAsReadyOnly($readyOnly);

        $result = app(BackfillEmployeeHistoricalCompletionsAction::class)->analyze();

        self::assertSame(2, $result->greenCount);
        self::assertSame(2, $result->waitingListCount);
        self::assertSame(1, $result->overlapCount);
        self::assertSame(3, $result->unionCount);
        self::assertSame(9, $result->expectedRowsTotal());
        self::assertSame(0, EmployeeHistoricalCompletion::query()->count());
    }
}
