<?php

namespace App\Actions\Hr;

use App\Enums\EmployeeHistoricalCompletionStage;
use App\Models\Employee;
use App\Models\EmployeeHistoricalCompletion;
use App\Support\Hr\HistoricalCompletion\HistoricalCompletionAuditResult;
use App\Support\Hr\HistoricalCompletion\HistoricalCompletionCandidate;
use Illuminate\Support\Facades\DB;

/**
 * Issue #12 — Historical Training/Practical/Uniform completion.
 *
 * Approved rule: every Green ∪ Waiting List employee (deduplicated — one
 * person in both sources gets ONE set of 3 rows, never 6) historically
 * completed all three of Training/Practical/Uniform. REGISTRATION Stage=
 * "Ready" is explicitly ignored — it is never part of either signal below,
 * so it is naturally excluded without any special-case code.
 *
 * This NEVER touches status/pipeline_stage/live queues/Damiin/Contract/
 * Uniform/Training/Practical/Waiting, and NEVER creates fake operational
 * Training/Practical/Uniform records — see employee_historical_completions'
 * own migration. Idempotent: safe to re-run, upserts on the
 * (employee_id, stage) unique constraint.
 */
class BackfillEmployeeHistoricalCompletionsAction
{
    /**
     * Read-only — computes the exact candidate population and expected row
     * counts without writing anything. Safe to call anytime.
     */
    public function analyze(): HistoricalCompletionAuditResult
    {
        $candidates = $this->resolveCandidates();

        $green = 0;
        $waiting = 0;
        $overlap = 0;

        foreach ($candidates as $candidate) {
            if ($candidate->isGreen) {
                $green++;
            }
            if ($candidate->isWaitingList) {
                $waiting++;
            }
            if ($candidate->isGreen && $candidate->isWaitingList) {
                $overlap++;
            }
        }

        return new HistoricalCompletionAuditResult(
            greenCount: $green,
            waitingListCount: $waiting,
            overlapCount: $overlap,
            unionCount: count($candidates),
            candidates: $candidates,
            alreadyBackfilledRows: EmployeeHistoricalCompletion::query()->count(),
        );
    }

    /**
     * The actual write — idempotent (upsert on employee_id+stage), so it is
     * safe to re-run without creating duplicates. NOT called automatically;
     * this is the prepared write for the final, explicitly-approved
     * production batch only.
     */
    public function backfill(): int
    {
        $candidates = $this->resolveCandidates();
        $now = now();
        $rows = [];

        foreach ($candidates as $candidate) {
            foreach (EmployeeHistoricalCompletionStage::cases() as $stage) {
                $rows[] = [
                    'employee_id' => $candidate->employeeId,
                    'stage' => $stage->value,
                    'source' => 'excel_migration',
                    'source_reference' => $candidate->sourceReference(),
                    'source_notes' => null,
                    'completed_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $written = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('employee_historical_completions')->upsert(
                $chunk,
                ['employee_id', 'stage'],
                ['source', 'source_reference', 'source_notes', 'updated_at'],
            );
            $written += count($chunk);
        }

        return $written;
    }

    /**
     * @return list<HistoricalCompletionCandidate>
     */
    private function resolveCandidates(): array
    {
        $greenIds = Employee::query()
            ->whereRaw('LOWER(notes) LIKE ?', ['%hore shaqo loo geeyay%'])
            ->pluck('id')
            ->all();

        $waitingListIds = Employee::query()
            ->whereHas('statusHistories', function ($q) {
                $q->whereRaw('LOWER(note) LIKE ?', ['%waiting list:%'])
                    ->orWhereRaw('LOWER(note) LIKE ?', ['%new waiting list2026:%']);
            })
            ->pluck('id')
            ->all();

        $greenSet = array_flip($greenIds);
        $waitingSet = array_flip($waitingListIds);
        $allIds = array_unique([...$greenIds, ...$waitingListIds]);

        if ($allIds === []) {
            return [];
        }

        $employeeNumbers = Employee::query()
            ->whereIn('id', $allIds)
            ->pluck('employee_number', 'id');

        return array_values(array_map(
            fn (int $id): HistoricalCompletionCandidate => new HistoricalCompletionCandidate(
                employeeId: $id,
                employeeNumber: $employeeNumbers[$id] ?? '',
                isGreen: isset($greenSet[$id]),
                isWaitingList: isset($waitingSet[$id]),
            ),
            $allIds,
        ));
    }
}
