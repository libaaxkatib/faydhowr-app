<?php

namespace App\Console\Commands;

use App\Actions\Hr\BackfillEmployeeHistoricalCompletionsAction;
use Illuminate\Console\Command;

/**
 * Issue #12: thin CLI wrapper around BackfillEmployeeHistoricalCompletionsAction
 * (already unit-tested — see BackfillEmployeeHistoricalCompletionsActionTest).
 * Read-only unless --commit; idempotent either way.
 */
class BackfillHistoricalCompletionsCommand extends Command
{
    protected $signature = 'hr:backfill-historical-completions {--commit : Actually write to the database}';

    protected $description = 'Issue #12: backfill employee_historical_completions for Green/Waiting List employees. Read-only unless --commit.';

    public function handle(BackfillEmployeeHistoricalCompletionsAction $action): int
    {
        $result = $action->analyze();

        $this->info("Green: {$result->greenCount}");
        $this->info("Waiting List: {$result->waitingListCount}");
        $this->info("Overlap: {$result->overlapCount}");
        $this->info("Union (unique employees): {$result->unionCount}");
        $this->info("Expected rows (union x 3): {$result->expectedRowsTotal()}");
        $this->info("Already backfilled: {$result->alreadyBackfilledRows}");

        if (! $this->option('commit')) {
            $this->warn('DRY RUN — no database writes occurred. Re-run with --commit to apply.');

            return self::SUCCESS;
        }

        $written = $action->backfill();
        $this->info("Committed. Rows upserted: {$written} (expected {$result->expectedRowsTotal()}).");

        if ($written !== $result->expectedRowsTotal()) {
            $this->error('Mismatch between expected and written row counts — investigate before trusting this batch.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
