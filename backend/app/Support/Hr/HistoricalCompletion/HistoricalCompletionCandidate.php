<?php

namespace App\Support\Hr\HistoricalCompletion;

/**
 * One Green ∪ Waiting List employee who should receive all three historical
 * completion rows — see BackfillEmployeeHistoricalCompletionsAction.
 */
final readonly class HistoricalCompletionCandidate
{
    public function __construct(
        public int $employeeId,
        public string $employeeNumber,
        public bool $isGreen,
        public bool $isWaitingList,
    ) {}

    public function sourceReference(): string
    {
        return match (true) {
            $this->isGreen && $this->isWaitingList => 'Green + Waiting List (Excel migration)',
            $this->isGreen => 'Green (Excel migration, REGISTRATION "hore shaqo loo geeyay")',
            default => 'Waiting List (Excel migration)',
        };
    }
}
