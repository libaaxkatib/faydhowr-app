<?php

namespace App\DataTransferObjects\Accounting;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Immutable balance sheet derived from posted journal entry lines. Total
 * equity includes the current earnings (net profit of the range), so the
 * accounting equation Assets = Liabilities + Equity always holds for a
 * consistent double-entry ledger. Amounts are decimal strings.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class BalanceSheetData implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $totalAssets,
        public string $totalLiabilities,
        public string $totalEquity,
        public string $currentEarnings,
        public bool $isBalanced,
        public ?string $startDate,
        public ?string $endDate,
    ) {}

    /**
     * @return array{total_assets: string, total_liabilities: string, total_equity: string, current_earnings: string, is_balanced: bool, start_date: ?string, end_date: ?string}
     */
    public function toArray(): array
    {
        return [
            'total_assets' => $this->totalAssets,
            'total_liabilities' => $this->totalLiabilities,
            'total_equity' => $this->totalEquity,
            'current_earnings' => $this->currentEarnings,
            'is_balanced' => $this->isBalanced,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
    }

    /**
     * @return array{total_assets: string, total_liabilities: string, total_equity: string, current_earnings: string, is_balanced: bool, start_date: ?string, end_date: ?string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
