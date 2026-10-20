<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Enums\EmployeeStatus;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time (and safely repeatable) correction: an Active employee is working, so they are not in any recruitment pipeline stage.
 * Records that were activated before the rule existed still carry a stage (for example "Need Training") and show up in the stage
 * queues and counts. This clears the stage on those records ONLY:
 *
 *  - employees whose status is Active and that are not archived; Inactive, archived and every other status are never touched;
 *  - nothing else on the employee changes, and no history is deleted: each cleared stage is written to the status history
 *    (source "stage_cleanup", note naming the previous stage) and to the audit log (previous stage, who, when);
 *  - nothing is invented: no training, practical or other completion is recorded.
 *
 * DRY RUN unless --commit; a real run needs --admin-email of a Super Admin. Running it again finds nothing to do.
 */
class ClearActivePipelineStagesCommand extends Command
{
    protected $signature = 'hr:clear-active-pipeline-stages
        {--commit : Actually write to the database (default is a dry run)}
        {--admin-email= : Super Admin who performs the run (required with --commit)}
        {--report= : Write every record to this CSV path}';

    protected $description = 'Clear the pipeline stage of employees who are already Active (kept in the history). Dry run unless --commit.';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $actor = null;

        if ($commit) {
            $actor = Admin::query()->where('email', (string) $this->option('admin-email'))->first();

            if ($actor === null || $actor->role !== AdminRole::SuperAdmin) {
                $this->error('--commit needs --admin-email of an existing Super Admin.');

                return self::FAILURE;
            }
        }

        $base = fn () => Employee::query()->where('status', EmployeeStatus::Active->value)->whereNotNull('pipeline_stage');
        $byStage = $base()->selectRaw('pipeline_stage, count(*) as total')->groupBy('pipeline_stage')->pluck('total', 'pipeline_stage')->all();
        $total = array_sum($byStage);

        $this->line($commit ? '<info>COMMIT - writing as '.$actor->email.'</info>' : '<comment>DRY RUN - nothing will be written.</comment>');
        $this->line("Active employees that still carry a pipeline stage: {$total}".($byStage === [] ? '' : ' ('.implode(', ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($byStage), $byStage)).')'));

        $rows = [];
        $cleared = 0;

        foreach ($base()->orderBy('id')->get(['id']) as $candidate) {
            if (! $commit) {
                $e = Employee::query()->find($candidate->id);
                $rows[] = [$e->employee_number, $e->pipeline_stage?->value, 'would_clear'];

                continue;
            }

            $rows[] = DB::transaction(function () use ($candidate, $actor, &$cleared): array {
                $e = Employee::query()->whereKey($candidate->id)->lockForUpdate()->first();

                // Re-checked under the lock: someone may have changed the record since the list was read.
                if ($e === null || $e->status !== EmployeeStatus::Active || $e->pipeline_stage === null) {
                    return [$e?->employee_number, null, 'skipped_changed'];
                }

                $previous = $e->pipeline_stage;
                $e->update(['pipeline_stage' => null]);

                EmployeeStatusHistory::query()->create([
                    'employee_id' => $e->id,
                    'from_status' => EmployeeStatus::Active,
                    'to_status' => EmployeeStatus::Active,
                    'changed_by' => $actor->id,
                    'note' => "Pipeline stage '{$previous->label()}' cleared because the person is Active (data correction; the stage is kept here as history).",
                    'source' => 'stage_cleanup',
                ]);

                event(AuditEvent::record(
                    action: AuditAction::Update,
                    admin: $actor,
                    description: "Employee '{$e->full_name}' ({$e->employee_number}): pipeline stage '{$previous->label()}' cleared because the person is Active.",
                    entityType: Employee::class,
                    entityId: $e->id,
                    metadata: ['event' => 'pipeline_stage_cleared', 'previous_pipeline_stage' => $previous->value, 'status' => 'active'],
                ));

                $cleared++;

                return [$e->employee_number, $previous->value, 'cleared'];
            });
        }

        if ($commit) {
            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Active pipeline-stage correction: {$cleared} employee(s) cleared.",
                entityType: Employee::class,
                entityId: null,
                metadata: ['event' => 'pipeline_stage_cleanup_run', 'candidates' => $total, 'cleared' => $cleared, 'by_stage' => $byStage],
            ));
        }

        if ($this->option('report')) {
            $h = fopen((string) $this->option('report'), 'w');
            fputcsv($h, ['employee_number', 'previous_pipeline_stage', 'outcome'], ',', '"', '\\');
            foreach ($rows as $r) {
                fputcsv($h, $r, ',', '"', '\\');
            }
            fclose($h);
            $this->line('Report written: '.$this->option('report'));
        }

        $this->line($commit ? "Cleared: {$cleared}." : "Would clear: {$total}. Re-run with --commit --admin-email=... to apply.");

        return self::SUCCESS;
    }
}
