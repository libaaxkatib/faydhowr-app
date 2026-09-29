<?php

namespace App\Support\Hr\HistoricalCompletion;

/**
 * @property list<HistoricalCompletionCandidate> $candidates
 */
final readonly class HistoricalCompletionAuditResult
{
    /**
     * @param  list<HistoricalCompletionCandidate>  $candidates
     */
    public function __construct(
        public int $greenCount,
        public int $waitingListCount,
        public int $overlapCount,
        public int $unionCount,
        public array $candidates,
        public int $alreadyBackfilledRows,
    ) {}

    public function expectedRowsTotal(): int
    {
        return $this->unionCount * 3;
    }

    public function rowsRemaining(): int
    {
        return $this->expectedRowsTotal() - $this->alreadyBackfilledRows;
    }
}
