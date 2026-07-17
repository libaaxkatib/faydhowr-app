<?php

namespace App\DataTransferObjects\Accounting;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Immutable ledger balance for one account, derived from posted journal
 * entry lines. Amounts are decimal strings with two fraction digits.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class LedgerBalanceData implements Arrayable, JsonSerializable
{
    public function __construct(
        public int $accountId,
        public string $accountCode,
        public string $accountName,
        public string $totalDebit,
        public string $totalCredit,
        public string $currentBalance,
    ) {}

    /**
     * @return array{account_id: int, account_code: string, account_name: string, total_debit: string, total_credit: string, current_balance: string}
     */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'account_code' => $this->accountCode,
            'account_name' => $this->accountName,
            'total_debit' => $this->totalDebit,
            'total_credit' => $this->totalCredit,
            'current_balance' => $this->currentBalance,
        ];
    }

    /**
     * @return array{account_id: int, account_code: string, account_name: string, total_debit: string, total_credit: string, current_balance: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
