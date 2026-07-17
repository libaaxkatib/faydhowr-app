<?php

namespace App\Enums\Accounting;

use App\Enums\Accounting\Concerns\InteractsWithValues;

enum AccountType: string
{
    use InteractsWithValues;

    case Cash = 'cash';
    case Bank = 'bank';
    case AccountsReceivable = 'accounts_receivable';
    case Inventory = 'inventory';
    case FixedAssets = 'fixed_assets';
    case AccountsPayable = 'accounts_payable';
    case Tax = 'tax';
    case Sales = 'sales';
    case ServiceRevenue = 'service_revenue';
    case CostOfGoodsSold = 'cost_of_goods_sold';
    case OperatingExpense = 'operating_expense';
    case PayrollExpense = 'payroll_expense';
    case RetainedEarnings = 'retained_earnings';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Bank => 'Bank',
            self::AccountsReceivable => 'Accounts Receivable',
            self::Inventory => 'Inventory',
            self::FixedAssets => 'Fixed Assets',
            self::AccountsPayable => 'Accounts Payable',
            self::Tax => 'Tax',
            self::Sales => 'Sales',
            self::ServiceRevenue => 'Service Revenue',
            self::CostOfGoodsSold => 'Cost of Goods Sold',
            self::OperatingExpense => 'Operating Expense',
            self::PayrollExpense => 'Payroll Expense',
            self::RetainedEarnings => 'Retained Earnings',
        };
    }
}
