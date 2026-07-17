<?php

namespace Tests\Feature\Accounting;

use App\Contracts\Accounting\Services\ChartOfAccountServiceInterface;
use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\AccountStatus;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\NormalBalance;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

class AccountingDomainTypesTest extends TestCase
{
    /**
     * @var list<class-string>
     */
    private const ACCOUNTING_ENUMS = [
        AccountCategory::class,
        NormalBalance::class,
        AccountStatus::class,
        AccountType::class,
        JournalEntryStatus::class,
        AccountingPeriodStatus::class,
    ];

    public function test_account_category_exposes_the_expected_values(): void
    {
        $this->assertSame(
            ['assets', 'liabilities', 'equity', 'revenue', 'expenses'],
            AccountCategory::values(),
        );
    }

    public function test_normal_balance_exposes_the_expected_values(): void
    {
        $this->assertSame(['debit', 'credit'], NormalBalance::values());
    }

    public function test_account_status_exposes_the_expected_values(): void
    {
        $this->assertSame(['active', 'inactive'], AccountStatus::values());
    }

    public function test_journal_entry_status_exposes_the_expected_values(): void
    {
        $this->assertSame(['draft', 'posted', 'cancelled'], JournalEntryStatus::values());
    }

    public function test_accounting_period_status_exposes_the_expected_values(): void
    {
        $this->assertSame(['open', 'closed'], AccountingPeriodStatus::values());
    }

    public function test_account_type_exposes_the_expected_values(): void
    {
        $this->assertSame(
            [
                'cash',
                'bank',
                'accounts_receivable',
                'inventory',
                'fixed_assets',
                'accounts_payable',
                'tax',
                'sales',
                'service_revenue',
                'cost_of_goods_sold',
                'operating_expense',
                'payroll_expense',
                'retained_earnings',
            ],
            AccountType::values(),
        );
    }

    public function test_every_case_has_a_non_empty_label(): void
    {
        foreach (self::ACCOUNTING_ENUMS as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertNotSame(
                    '',
                    $case->label(),
                    "{$enum}::{$case->name} must expose a label.",
                );
            }
        }
    }

    public function test_labels_map_every_value_to_its_label(): void
    {
        foreach (self::ACCOUNTING_ENUMS as $enum) {
            $labels = $enum::labels();

            $this->assertSame($enum::values(), array_keys($labels));

            foreach ($enum::cases() as $case) {
                $this->assertSame($case->label(), $labels[$case->value]);
            }
        }
    }

    public function test_account_type_labels_are_human_readable(): void
    {
        $this->assertSame('Accounts Receivable', AccountType::AccountsReceivable->label());
        $this->assertSame('Cost of Goods Sold', AccountType::CostOfGoodsSold->label());
        $this->assertSame('Retained Earnings', AccountType::RetainedEarnings->label());
    }

    public function test_from_value_resolves_every_backing_value(): void
    {
        foreach (self::ACCOUNTING_ENUMS as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertSame($case, $enum::fromValue($case->value));
            }
        }
    }

    public function test_from_value_rejects_invalid_values(): void
    {
        foreach (self::ACCOUNTING_ENUMS as $enum) {
            try {
                $enum::fromValue('not-a-real-value');

                $this->fail("{$enum}::fromValue() must reject invalid values.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('not-a-real-value', $exception->getMessage());
                $this->assertStringContainsString($enum, $exception->getMessage());
            }
        }
    }

    public function test_chart_of_account_service_interface_references_the_domain_enums(): void
    {
        $docComment = (string) (new ReflectionClass(ChartOfAccountServiceInterface::class))->getDocComment();

        $chartEnums = [
            AccountCategory::class,
            NormalBalance::class,
            AccountStatus::class,
            AccountType::class,
        ];

        foreach ($chartEnums as $enum) {
            $this->assertStringContainsString(
                '@see '.(new ReflectionClass($enum))->getShortName(),
                $docComment,
                "ChartOfAccountServiceInterface must reference {$enum} instead of raw strings.",
            );
        }
    }
}
