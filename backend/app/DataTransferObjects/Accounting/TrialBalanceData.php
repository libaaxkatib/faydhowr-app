<?php

namespace App\DataTransferObjects\Accounting;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Immutable trial balance derived from posted journal entry lines. In a
 * consistent double-entry ledger total debit always equals total credit;
 * isBalanced surfaces that invariant to consumers.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class TrialBalanceData implements Arrayable, JsonSerializable
{
    /**
     * @param  list<TrialBalanceRowData>  $rows
     */
    public function __construct(
        public array $rows,
        public string $totalDebit,
        public string $totalCredit,
        public bool $isBalanced,
        public ?string $startDate,
        public ?string $endDate,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, total_debit: string, total_credit: string, is_balanced: bool, start_date: ?string, end_date: ?string}
     */
    public function toArray(): array
    {
        return [
            'rows' => array_map(
                fn (TrialBalanceRowData $row): array => $row->toArray(),
                $this->rows,
            ),
            'total_debit' => $this->totalDebit,
            'total_credit' => $this->totalCredit,
            'is_balanced' => $this->isBalanced,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total_debit: string, total_credit: string, is_balanced: bool, start_date: ?string, end_date: ?string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
