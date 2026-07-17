<?php

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Models\Account;
use App\Services\Accounting\DefaultChartOfAccountsService;
use Database\Seeders\Accounting\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class ChartOfAccountsSeedingTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_ACCOUNT_COUNT = 21;

    public function test_seeder_creates_the_default_chart_of_accounts(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $this->assertSame(self::EXPECTED_ACCOUNT_COUNT, Account::query()->count());

        foreach ([
            '1000' => 'Assets',
            '1100' => 'Current Assets',
            '1110' => 'Cash',
            '1120' => 'Bank',
            '1130' => 'Accounts Receivable',
            '1140' => 'Inventory',
            '1500' => 'Fixed Assets',
            '1510' => 'Equipment',
            '2000' => 'Liabilities',
            '2100' => 'Current Liabilities',
            '2110' => 'Accounts Payable',
            '2120' => 'Tax Payable',
            '3000' => 'Equity',
            '3100' => 'Retained Earnings',
            '4000' => 'Revenue',
            '4100' => 'Service Revenue',
            '4200' => 'Sales Revenue',
            '5000' => 'Expenses',
            '5100' => 'Cost of Goods Sold',
            '5200' => 'Operating Expense',
            '5300' => 'Payroll Expense',
        ] as $code => $name) {
            $this->assertDatabaseHas('accounts', [
                'code' => (string) $code,
                'name' => $name,
            ]);
        }
    }

    public function test_seeded_hierarchy_links_children_to_the_correct_parents(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        foreach ([
            '1100' => '1000',
            '1110' => '1100',
            '1120' => '1100',
            '1130' => '1100',
            '1140' => '1100',
            '1500' => '1000',
            '1510' => '1500',
            '2100' => '2000',
            '2110' => '2100',
            '2120' => '2100',
            '3100' => '3000',
            '4100' => '4000',
            '4200' => '4000',
            '5100' => '5000',
            '5200' => '5000',
            '5300' => '5000',
        ] as $childCode => $parentCode) {
            $child = Account::query()->where('code', (string) $childCode)->firstOrFail();

            $this->assertSame(
                (string) $parentCode,
                $child->parent?->code,
                "Account {$childCode} must be a child of {$parentCode}.",
            );
        }

        foreach (['1000', '2000', '3000', '4000', '5000'] as $rootCode) {
            $root = Account::query()->where('code', $rootCode)->firstOrFail();

            $this->assertNull($root->parent_account_id, "Root account {$rootCode} must have no parent.");
        }
    }

    public function test_every_group_account_is_flagged_and_has_children(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $groups = Account::query()->where('is_group', true)->with('children')->get();

        $this->assertSame(
            ['1000', '1100', '1500', '2000', '2100', '3000', '4000', '5000'],
            $groups->pluck('code')->sort()->values()->all(),
        );

        foreach ($groups as $group) {
            $this->assertGreaterThan(
                0,
                $group->children->count(),
                "Group account {$group->code} must have children.",
            );
        }

        $this->assertSame(
            0,
            Account::query()->where('is_group', false)->has('children')->count(),
            'Non-group accounts must not have children.',
        );
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->assertSame(self::EXPECTED_ACCOUNT_COUNT, Account::query()->count());
        $this->assertSame(
            self::EXPECTED_ACCOUNT_COUNT,
            Account::query()->distinct()->count('code'),
        );
    }

    public function test_seeded_accounts_store_the_expected_enum_values(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        foreach ([
            '1110' => [AccountType::Cash, AccountCategory::Assets, NormalBalance::Debit],
            '1120' => [AccountType::Bank, AccountCategory::Assets, NormalBalance::Debit],
            '1130' => [AccountType::AccountsReceivable, AccountCategory::Assets, NormalBalance::Debit],
            '1140' => [AccountType::Inventory, AccountCategory::Assets, NormalBalance::Debit],
            '1510' => [AccountType::FixedAssets, AccountCategory::Assets, NormalBalance::Debit],
            '2110' => [AccountType::AccountsPayable, AccountCategory::Liabilities, NormalBalance::Credit],
            '2120' => [AccountType::Tax, AccountCategory::Liabilities, NormalBalance::Credit],
            '3100' => [AccountType::RetainedEarnings, AccountCategory::Equity, NormalBalance::Credit],
            '4100' => [AccountType::ServiceRevenue, AccountCategory::Revenue, NormalBalance::Credit],
            '4200' => [AccountType::Sales, AccountCategory::Revenue, NormalBalance::Credit],
            '5100' => [AccountType::CostOfGoodsSold, AccountCategory::Expenses, NormalBalance::Debit],
            '5200' => [AccountType::OperatingExpense, AccountCategory::Expenses, NormalBalance::Debit],
            '5300' => [AccountType::PayrollExpense, AccountCategory::Expenses, NormalBalance::Debit],
        ] as $code => [$type, $category, $normalBalance]) {
            $this->assertDatabaseHas('accounts', [
                'code' => (string) $code,
                'account_type' => $type->value,
                'account_category' => $category->value,
                'normal_balance' => $normalBalance->value,
                'status' => 'active',
            ]);
        }
    }

    public function test_seeder_receives_the_default_chart_service_through_dependency_injection(): void
    {
        $parameterTypes = array_map(
            function ($parameter): string {
                $type = $parameter->getType();

                $this->assertInstanceOf(ReflectionNamedType::class, $type);

                return $type->getName();
            },
            (new ReflectionClass(ChartOfAccountsSeeder::class))->getMethod('run')->getParameters(),
        );

        $this->assertSame([DefaultChartOfAccountsService::class], $parameterTypes);
        $this->assertInstanceOf(
            DefaultChartOfAccountsService::class,
            $this->app->make(DefaultChartOfAccountsService::class),
        );
    }
}
