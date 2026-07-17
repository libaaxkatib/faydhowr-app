<?php

namespace App\DataTransferObjects\Accounting;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Immutable income statement derived from posted journal entry lines.
 * Amounts are decimal strings with two fraction digits.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class IncomeStatementData implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $totalRevenue,
        public string $totalExpenses,
        public string $netProfit,
        public ?string $startDate,
        public ?string $endDate,
    ) {}

    /**
     * @return array{total_revenue: string, total_expenses: string, net_profit: string, start_date: ?string, end_date: ?string}
     */
    public function toArray(): array
    {
        return [
            'total_revenue' => $this->totalRevenue,
            'total_expenses' => $this->totalExpenses,
            'net_profit' => $this->netProfit,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
    }

    /**
     * @return array{total_revenue: string, total_expenses: string, net_profit: string, start_date: ?string, end_date: ?string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
