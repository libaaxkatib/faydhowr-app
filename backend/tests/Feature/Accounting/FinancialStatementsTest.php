<?php

namespace Tests\Feature\Accounting;

use App\Contracts\Accounting\AccountingManagerInterface;
use App\Contracts\Accounting\Repositories\FinancialReportRepositoryInterface;
use App\Contracts\Accounting\Services\FinancialReportServiceInterface;
use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\NormalBalance;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Repositories\Accounting\FinancialReportRepository;
use App\Services\Accounting\Services\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class FinancialStatementsTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    private Account $payable;

    private Account $capital;

    private Account $revenue;

    private Account $expense;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cash = Account::factory()
            ->category(AccountCategory::Assets)
            ->create(['code' => '1110']);
        $this->payable = Account::factory()
            ->category(AccountCategory::Liabilities)
            ->normalBalance(NormalBalance::Credit)
            ->create(['code' => '2110']);
        $this->capital = Account::factory()
            ->category(AccountCategory::Equity)
            ->normalBalance(NormalBalance::Credit)
            ->create(['code' => '3100']);
        $this->revenue = Account::factory()
            ->category(AccountCategory::Revenue)
            ->normalBalance(NormalBalance::Credit)
            ->create(['code' => '4200']);
        $this->expense = Account::factory()
            ->category(AccountCategory::Expenses)
            ->create(['code' => '5200']);
    }

    public function test_financial_report_repository_interface_resolves_to_a_singleton_repository(): void
    {
        $repository = $this->app->make(FinancialReportRepositoryInterface::class);

        $this->assertInstanceOf(FinancialReportRepository::class, $repository);
        $this->assertSame($repository, $this->app->make(FinancialReportRepositoryInterface::class));
    }

    public function test_income_statement_reports_revenue_expenses_and_net_profit(): void
    {
        $this->seedPostedActivity();

        $statement = $this->app->make(AccountingManagerInterface::class)
            ->financialReports()
            ->incomeStatement();

        $this->assertSame('1000.00', $statement->totalRevenue);
        $this->assertSame('400.00', $statement->totalExpenses);
        $this->assertSame('600.00', $statement->netProfit);
    }

    public function test_balance_sheet_satisfies_the_accounting_equation(): void
    {
        $this->seedPostedActivity();

        $sheet = $this->app->make(FinancialReportServiceInterface::class)->balanceSheet();

        $this->assertSame('1100.00', $sheet->totalAssets);
        $this->assertSame('0.00', $sheet->totalLiabilities);
        $this->assertSame('1100.00', $sheet->totalEquity);
        $this->assertSame('600.00', $sheet->currentEarnings);
        $this->assertTrue($sheet->isBalanced);
    }

    public function test_balance_sheet_includes_liabilities(): void
    {
        // Buy inventory-like expense on credit: expense 250 / payable 250.
        $this->postedEntry('2026-07-12', [
            [$this->expense, '250.00', '0.00'],
            [$this->payable, '0.00', '250.00'],
        ]);

        $sheet = $this->app->make(FinancialReportServiceInterface::class)->balanceSheet();

        $this->assertSame('0.00', $sheet->totalAssets);
        $this->assertSame('250.00', $sheet->totalLiabilities);
        $this->assertSame('-250.00', $sheet->totalEquity);
        $this->assertSame('-250.00', $sheet->currentEarnings);
        $this->assertTrue($sheet->isBalanced);
    }

    public function test_statements_ignore_draft_and_cancelled_entries(): void
    {
        $this->entry(JournalEntryStatus::Draft, '2026-07-10', [
            [$this->cash, '999.00', '0.00'],
            [$this->revenue, '0.00', '999.00'],
        ]);
        $this->entry(JournalEntryStatus::Cancelled, '2026-07-10', [
            [$this->cash, '888.00', '0.00'],
            [$this->revenue, '0.00', '888.00'],
        ]);

        $service = $this->app->make(FinancialReportServiceInterface::class);

        $this->assertSame('0.00', $service->incomeStatement()->totalRevenue);
        $this->assertSame('0.00', $service->balanceSheet()->totalAssets);
    }

    public function test_statements_support_date_ranges_and_periods(): void
    {
        $period = AccountingPeriod::factory()->spanning('2026-07-01', '2026-07-31')->create();

        $this->postedEntry('2026-06-15', [
            [$this->cash, '300.00', '0.00'],
            [$this->revenue, '0.00', '300.00'],
        ]);
        $this->postedEntry('2026-07-15', [
            [$this->cash, '1000.00', '0.00'],
            [$this->revenue, '0.00', '1000.00'],
        ]);

        $service = $this->app->make(FinancialReportServiceInterface::class);

        $rangeStatement = $service->incomeStatement(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));
        $this->assertSame('1000.00', $rangeStatement->totalRevenue);
        $this->assertSame('2026-07-01', $rangeStatement->startDate);
        $this->assertSame('2026-07-31', $rangeStatement->endDate);

        $periodStatement = $service->incomeStatementForPeriod($period);
        $this->assertSame('1000.00', $periodStatement->totalRevenue);

        $periodSheet = $service->balanceSheetForPeriod($period);
        $this->assertSame('1000.00', $periodSheet->totalAssets);
        $this->assertTrue($periodSheet->isBalanced);

        $allTimeStatement = $service->incomeStatement();
        $this->assertSame('1300.00', $allTimeStatement->totalRevenue);
    }

    public function test_empty_data_produces_zero_statements(): void
    {
        $service = $this->app->make(FinancialReportServiceInterface::class);

        $statement = $service->incomeStatement();
        $this->assertSame([
            'total_revenue' => '0.00',
            'total_expenses' => '0.00',
            'net_profit' => '0.00',
            'start_date' => null,
            'end_date' => null,
        ], $statement->toArray());

        $sheet = $service->balanceSheet();
        $this->assertSame([
            'total_assets' => '0.00',
            'total_liabilities' => '0.00',
            'total_equity' => '0.00',
            'current_earnings' => '0.00',
            'is_balanced' => true,
            'start_date' => null,
            'end_date' => null,
        ], $sheet->toArray());
    }

    public function test_service_depends_on_the_repository_interface(): void
    {
        $parameterTypes = array_map(
            function ($parameter): string {
                $type = $parameter->getType();

                $this->assertInstanceOf(ReflectionNamedType::class, $type);

                return $type->getName();
            },
            (new ReflectionClass(FinancialReportService::class))->getConstructor()->getParameters(),
        );

        $this->assertSame([FinancialReportRepositoryInterface::class], $parameterTypes);
    }

    /**
     * Owner invests 500 cash, earns 1000 revenue in cash, pays 400 expenses
     * in cash: assets 1100, equity 500, earnings 600.
     */
    private function seedPostedActivity(): void
    {
        $this->postedEntry('2026-07-05', [
            [$this->cash, '500.00', '0.00'],
            [$this->capital, '0.00', '500.00'],
        ]);
        $this->postedEntry('2026-07-10', [
            [$this->cash, '1000.00', '0.00'],
            [$this->revenue, '0.00', '1000.00'],
        ]);
        $this->postedEntry('2026-07-20', [
            [$this->expense, '400.00', '0.00'],
            [$this->cash, '0.00', '400.00'],
        ]);
    }

    /**
     * @param  list<array{0: Account, 1: string, 2: string}>  $lines
     */
    private function postedEntry(string $entryDate, array $lines): JournalEntry
    {
        return $this->entry(JournalEntryStatus::Posted, $entryDate, $lines);
    }

    /**
     * @param  list<array{0: Account, 1: string, 2: string}>  $lines
     */
    private function entry(JournalEntryStatus $status, string $entryDate, array $lines): JournalEntry
    {
        $entry = JournalEntry::factory()->status($status)->create(['entry_date' => $entryDate]);

        foreach ($lines as [$account, $debit, $credit]) {
            JournalEntryLine::factory()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $account->id,
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }

        return $entry;
    }
}
