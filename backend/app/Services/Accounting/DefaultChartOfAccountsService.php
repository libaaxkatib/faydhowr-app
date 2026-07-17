<?php

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountStatus;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Models\Account;

/**
 * Creates the default Fayadhowr ERP chart of accounts. Idempotent: accounts
 * are keyed by code, so running the seeding twice never duplicates rows.
 *
 * Group accounts carry a representative account_type (the schema requires
 * one); postings will only ever target non-group accounts.
 */
class DefaultChartOfAccountsService
{
    public function seed(): void
    {
        foreach ($this->accountTree() as $definition) {
            $this->createAccount($definition, null);
        }
    }

    /**
     * @param  array{code: string, name: string, type: AccountType, category: AccountCategory, is_group?: bool, children?: list<array<string, mixed>>}  $definition
     */
    private function createAccount(array $definition, ?Account $parent): void
    {
        $account = Account::query()->firstOrCreate(
            ['code' => $definition['code']],
            [
                'name' => $definition['name'],
                'account_type' => $definition['type'],
                'account_category' => $definition['category'],
                'parent_account_id' => $parent?->id,
                'is_group' => $definition['is_group'] ?? false,
                'normal_balance' => $this->normalBalanceFor($definition['category']),
                'status' => AccountStatus::Active,
            ],
        );

        foreach ($definition['children'] ?? [] as $child) {
            $this->createAccount($child, $account);
        }
    }

    private function normalBalanceFor(AccountCategory $category): NormalBalance
    {
        return match ($category) {
            AccountCategory::Assets,
            AccountCategory::Expenses => NormalBalance::Debit,
            AccountCategory::Liabilities,
            AccountCategory::Equity,
            AccountCategory::Revenue => NormalBalance::Credit,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountTree(): array
    {
        return [
            [
                'code' => '1000',
                'name' => 'Assets',
                'type' => AccountType::Cash,
                'category' => AccountCategory::Assets,
                'is_group' => true,
                'children' => [
                    [
                        'code' => '1100',
                        'name' => 'Current Assets',
                        'type' => AccountType::Cash,
                        'category' => AccountCategory::Assets,
                        'is_group' => true,
                        'children' => [
                            [
                                'code' => '1110',
                                'name' => 'Cash',
                                'type' => AccountType::Cash,
                                'category' => AccountCategory::Assets,
                            ],
                            [
                                'code' => '1120',
                                'name' => 'Bank',
                                'type' => AccountType::Bank,
                                'category' => AccountCategory::Assets,
                            ],
                            [
                                'code' => '1130',
                                'name' => 'Accounts Receivable',
                                'type' => AccountType::AccountsReceivable,
                                'category' => AccountCategory::Assets,
                            ],
                            [
                                'code' => '1140',
                                'name' => 'Inventory',
                                'type' => AccountType::Inventory,
                                'category' => AccountCategory::Assets,
                            ],
                        ],
                    ],
                    [
                        'code' => '1500',
                        'name' => 'Fixed Assets',
                        'type' => AccountType::FixedAssets,
                        'category' => AccountCategory::Assets,
                        'is_group' => true,
                        'children' => [
                            [
                                'code' => '1510',
                                'name' => 'Equipment',
                                'type' => AccountType::FixedAssets,
                                'category' => AccountCategory::Assets,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'code' => '2000',
                'name' => 'Liabilities',
                'type' => AccountType::AccountsPayable,
                'category' => AccountCategory::Liabilities,
                'is_group' => true,
                'children' => [
                    [
                        'code' => '2100',
                        'name' => 'Current Liabilities',
                        'type' => AccountType::AccountsPayable,
                        'category' => AccountCategory::Liabilities,
                        'is_group' => true,
                        'children' => [
                            [
                                'code' => '2110',
                                'name' => 'Accounts Payable',
                                'type' => AccountType::AccountsPayable,
                                'category' => AccountCategory::Liabilities,
                            ],
                            [
                                'code' => '2120',
                                'name' => 'Tax Payable',
                                'type' => AccountType::Tax,
                                'category' => AccountCategory::Liabilities,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'code' => '3000',
                'name' => 'Equity',
                'type' => AccountType::RetainedEarnings,
                'category' => AccountCategory::Equity,
                'is_group' => true,
                'children' => [
                    [
                        'code' => '3100',
                        'name' => 'Retained Earnings',
                        'type' => AccountType::RetainedEarnings,
                        'category' => AccountCategory::Equity,
                    ],
                ],
            ],
            [
                'code' => '4000',
                'name' => 'Revenue',
                'type' => AccountType::Sales,
                'category' => AccountCategory::Revenue,
                'is_group' => true,
                'children' => [
                    [
                        'code' => '4100',
                        'name' => 'Service Revenue',
                        'type' => AccountType::ServiceRevenue,
                        'category' => AccountCategory::Revenue,
                    ],
                    [
                        'code' => '4200',
                        'name' => 'Sales Revenue',
                        'type' => AccountType::Sales,
                        'category' => AccountCategory::Revenue,
                    ],
                ],
            ],
            [
                'code' => '5000',
                'name' => 'Expenses',
                'type' => AccountType::OperatingExpense,
                'category' => AccountCategory::Expenses,
                'is_group' => true,
                'children' => [
                    [
                        'code' => '5100',
                        'name' => 'Cost of Goods Sold',
                        'type' => AccountType::CostOfGoodsSold,
                        'category' => AccountCategory::Expenses,
                    ],
                    [
                        'code' => '5200',
                        'name' => 'Operating Expense',
                        'type' => AccountType::OperatingExpense,
                        'category' => AccountCategory::Expenses,
                    ],
                    [
                        'code' => '5300',
                        'name' => 'Payroll Expense',
                        'type' => AccountType::PayrollExpense,
                        'category' => AccountCategory::Expenses,
                    ],
                ],
            ],
        ];
    }
}
