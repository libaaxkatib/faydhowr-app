<?php

namespace Tests\Feature\Accounting;

use App\Contracts\Accounting\AccountingManagerInterface;
use App\Contracts\Accounting\Repositories\LedgerRepositoryInterface;
use App\Contracts\Accounting\Services\LedgerServiceInterface;
use App\DataTransferObjects\Accounting\LedgerBalanceData;
use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\NormalBalance;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Repositories\Accounting\LedgerRepository;
use App\Services\Accounting\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class LedgerBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ledger_repository_interface_resolves_to_a_singleton_repository(): void
    {
        $repository = $this->app->make(LedgerRepositoryInterface::class);

        $this->assertInstanceOf(LedgerRepository::class, $repository);
        $this->assertSame($repository, $this->app->make(LedgerRepositoryInterface::class));
    }

    public function test_only_posted_entries_are_counted(): void
    {
        $account = Account::factory()->create();

        $this->lineFor($account, JournalEntryStatus::Posted, debit: '100.00');
        $this->lineFor($account, JournalEntryStatus::Posted, credit: '40.00');
        $this->lineFor($account, JournalEntryStatus::Draft, debit: '999.00');
        $this->lineFor($account, JournalEntryStatus::Cancelled, credit: '888.00');

        $balance = $this->app->make(LedgerServiceInterface::class)->balanceForAccount($account);

        $this->assertSame('100.00', $balance->totalDebit);
        $this->assertSame('40.00', $balance->totalCredit);
        $this->assertSame('60.00', $balance->currentBalance);
    }

    public function test_debit_normal_account_balance_is_debit_minus_credit(): void
    {
        $account = Account::factory()->normalBalance(NormalBalance::Debit)->create();

        $this->lineFor($account, JournalEntryStatus::Posted, debit: '250.00');
        $this->lineFor($account, JournalEntryStatus::Posted, credit: '400.00');

        $balance = $this->app->make(LedgerServiceInterface::class)->balanceForAccount($account);

        $this->assertSame('250.00', $balance->totalDebit);
        $this->assertSame('400.00', $balance->totalCredit);
        $this->assertSame('-150.00', $balance->currentBalance);
    }

    public function test_credit_normal_account_balance_is_credit_minus_debit(): void
    {
        $account = Account::factory()
            ->category(AccountCategory::Revenue)
            ->normalBalance(NormalBalance::Credit)
            ->create();

        $this->lineFor($account, JournalEntryStatus::Posted, credit: '900.00');
        $this->lineFor($account, JournalEntryStatus::Posted, debit: '150.50');

        $balance = $this->app->make(LedgerServiceInterface::class)->balanceForAccount($account);

        $this->assertSame('150.50', $balance->totalDebit);
        $this->assertSame('900.00', $balance->totalCredit);
        $this->assertSame('749.50', $balance->currentBalance);
    }

    public function test_account_without_postings_has_zero_balance(): void
    {
        $account = Account::factory()->create();

        $balance = $this->app->make(LedgerServiceInterface::class)->balanceForAccount($account);

        $this->assertSame('0.00', $balance->totalDebit);
        $this->assertSame('0.00', $balance->totalCredit);
        $this->assertSame('0.00', $balance->currentBalance);
    }

    public function test_balance_is_exposed_through_the_accounting_manager_as_a_dto(): void
    {
        $account = Account::factory()->create(['code' => '1110', 'name' => 'Cash']);
        $this->lineFor($account, JournalEntryStatus::Posted, debit: '75.25');
        $this->lineFor($account, JournalEntryStatus::Posted, credit: '25.25');

        $balance = $this->app->make(AccountingManagerInterface::class)
            ->ledger()
            ->balanceForAccount($account);

        $this->assertInstanceOf(LedgerBalanceData::class, $balance);
        $this->assertSame([
            'account_id' => $account->id,
            'account_code' => '1110',
            'account_name' => 'Cash',
            'total_debit' => '75.25',
            'total_credit' => '25.25',
            'current_balance' => '50.00',
        ], $balance->toArray());
    }

    public function test_balance_uses_one_aggregate_query_instead_of_loading_lines(): void
    {
        $account = Account::factory()->create();

        foreach (range(1, 5) as $i) {
            $this->lineFor($account, JournalEntryStatus::Posted, debit: '10.00');
        }

        $repository = $this->app->make(LedgerRepositoryInterface::class);

        DB::enableQueryLog();
        $repository->balanceForAccount($account);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'Balance must be computed with a single aggregate query.');
        $this->assertMatchesRegularExpression(
            '/sum\(["`]?debit["`]?\)/i',
            $queries[0]['query'],
            'Balance query must aggregate with SUM() instead of loading journal lines.',
        );
    }

    public function test_ledger_service_depends_on_the_repository_interface(): void
    {
        $parameterTypes = array_map(
            function ($parameter): string {
                $type = $parameter->getType();

                $this->assertInstanceOf(ReflectionNamedType::class, $type);

                return $type->getName();
            },
            (new ReflectionClass(LedgerService::class))->getConstructor()->getParameters(),
        );

        $this->assertSame([LedgerRepositoryInterface::class], $parameterTypes);
    }

    private function lineFor(
        Account $account,
        JournalEntryStatus $status,
        string $debit = '0.00',
        string $credit = '0.00',
    ): JournalEntryLine {
        return JournalEntryLine::factory()->create([
            'journal_entry_id' => JournalEntry::factory()->status($status)->create()->id,
            'account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
        ]);
    }
}
