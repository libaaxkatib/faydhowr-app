<?php

namespace App\DataTransferObjects\Accounting;

use App\Enums\Accounting\NormalBalance;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Immutable trial balance line for one account. Amounts are decimal
 * strings with two fraction digits.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class TrialBalanceRowData implements Arrayable, JsonSerializable
{
    public function __construct(
        public int $accountId,
        public string $accountCode,
        public string $accountName,
        public NormalBalance $normalBalance,
        public string $totalDebit,
        public string $totalCredit,
        public string $currentBalance,
    ) {}

    /**
     * @return array{account_id: int, account_code: string, account_name: string, normal_balance: string, total_debit: string, total_credit: string, current_balance: string}
     */
    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'account_code' => $this->accountCode,
            'account_name' => $this->accountName,
            'normal_balance' => $this->normalBalance->value,
            'total_debit' => $this->totalDebit,
            'total_credit' => $this->totalCredit,
            'current_balance' => $this->currentBalance,
        ];
    }

    /**
     * @return array{account_id: int, account_code: string, account_name: string, normal_balance: string, total_debit: string, total_credit: string, current_balance: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
