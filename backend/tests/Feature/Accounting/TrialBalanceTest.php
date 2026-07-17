<?php

namespace Tests\Feature\Accounting;

use App\Contracts\Accounting\AccountingManagerInterface;
use App\Contracts\Accounting\Repositories\TrialBalanceRepositoryInterface;
use App\Contracts\Accounting\Services\TrialBalanceServiceInterface;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\NormalBalance;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Repositories\Accounting\TrialBalanceRepository;
use App\Services\Accounting\Services\TrialBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class TrialBalanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_trial_balance_repository_interface_resolves_to_a_singleton_repository(): void
    {
        $repository = $this->app->make(TrialBalanceRepositoryInterface::class);

        $this->assertInstanceOf(TrialBalanceRepository::class, $repository);
        $this->assertSame($repository, $this->app->make(TrialBalanceRepositoryInterface::class));
    }

    public function test_trial_balance_is_balanced_and_ordered_by_account_code(): void
    {
        $cash = Account::factory()->create(['code' => '1110', 'name' => 'Cash']);
        $revenue = Account::factory()
            ->normalBalance(NormalBalance::Credit)
            ->create(['code' => '4200', 'name' => 'Sales Revenue']);

        $this->postedEntry('2026-07-10', [
            [$cash, '800.00', '0.00'],
            [$revenue, '0.00', '800.00'],
        ]);

        $trialBalance = $this->app->make(AccountingManagerInterface::class)
            ->trialBalance()
            ->generate();

        $this->assertTrue($trialBalance->isBalanced);
        $this->assertSame('800.00', $trialBalance->totalDebit);
        $this->assertSame('800.00', $trialBalance->totalCredit);
        $this->assertSame(['1110', '4200'], array_map(
            fn ($row): string => $row->accountCode,
            $trialBalance->rows,
        ));

        [$cashRow, $revenueRow] = $trialBalance->rows;

        $this->assertSame('800.00', $cashRow->totalDebit);
        $this->assertSame('800.00', $cashRow->currentBalance);
        $this->assertSame(NormalBalance::Debit, $cashRow->normalBalance);
        $this->assertSame('800.00', $revenueRow->totalCredit);
        $this->assertSame('800.00', $revenueRow->currentBalance);
        $this->assertSame(NormalBalance::Credit, $revenueRow->normalBalance);
    }

    public function test_draft_and_cancelled_entries_are_excluded(): void
    {
        $cash = Account::factory()->create(['code' => '1110']);
        $revenue = Account::factory()->normalBalance(NormalBalance::Credit)->create(['code' => '4200']);

        $this->entry(JournalEntryStatus::Draft, '2026-07-10', [
            [$cash, '999.00', '0.00'],
            [$revenue, '0.00', '999.00'],
        ]);
        $this->entry(JournalEntryStatus::Cancelled, '2026-07-10', [
            [$cash, '888.00', '0.00'],
            [$revenue, '0.00', '888.00'],
        ]);

        $trialBalance = $this->app->make(TrialBalanceServiceInterface::class)->generate();

        $this->assertSame([], $trialBalance->rows);
        $this->assertSame('0.00', $trialBalance->totalDebit);
        $this->assertSame('0.00', $trialBalance->totalCredit);
        $this->assertTrue($trialBalance->isBalanced);
    }

    public function test_date_range_filters_entries_by_entry_date(): void
    {
        $cash = Account::factory()->create(['code' => '1110']);
        $revenue = Account::factory()->normalBalance(NormalBalance::Credit)->create(['code' => '4200']);

        $this->postedEntry('2026-06-15', [
            [$cash, '100.00', '0.00'],
            [$revenue, '0.00', '100.00'],
        ]);
        $this->postedEntry('2026-07-15', [
            [$cash, '250.00', '0.00'],
            [$revenue, '0.00', '250.00'],
        ]);

        $trialBalance = $this->app->make(TrialBalanceServiceInterface::class)->generate(
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
        );

        $this->assertSame('250.00', $trialBalance->totalDebit);
        $this->assertSame('250.00', $trialBalance->totalCredit);
        $this->assertSame('2026-07-01', $trialBalance->startDate);
        $this->assertSame('2026-07-31', $trialBalance->endDate);
    }

    public function test_accounting_period_filters_entries_to_the_period_range(): void
    {
        $period = AccountingPeriod::factory()->spanning('2026-07-01', '2026-07-31')->create();
        $cash = Account::factory()->create(['code' => '1110']);
        $revenue = Account::factory()->normalBalance(NormalBalance::Credit)->create(['code' => '4200']);

        $this->postedEntry('2026-07-20', [
            [$cash, '300.00', '0.00'],
            [$revenue, '0.00', '300.00'],
        ]);
        $this->postedEntry('2026-08-05', [
            [$cash, '400.00', '0.00'],
            [$revenue, '0.00', '400.00'],
        ]);

        $trialBalance = $this->app->make(TrialBalanceServiceInterface::class)->generateForPeriod($period);

        $this->assertSame('300.00', $trialBalance->totalDebit);
        $this->assertSame('300.00', $trialBalance->totalCredit);
        $this->assertSame('2026-07-01', $trialBalance->startDate);
        $this->assertSame('2026-07-31', $trialBalance->endDate);
    }

    public function test_empty_period_produces_an_empty_balanced_trial_balance(): void
    {
        $period = AccountingPeriod::factory()->spanning('2026-01-01', '2026-01-31')->create();

        $trialBalance = $this->app->make(TrialBalanceServiceInterface::class)->generateForPeriod($period);

        $this->assertSame([], $trialBalance->rows);
        $this->assertSame('0.00', $trialBalance->totalDebit);
        $this->assertSame('0.00', $trialBalance->totalCredit);
        $this->assertTrue($trialBalance->isBalanced);
    }

    public function test_dto_serializes_rows_and_totals(): void
    {
        $cash = Account::factory()->create(['code' => '1110', 'name' => 'Cash']);
        $revenue = Account::factory()
            ->normalBalance(NormalBalance::Credit)
            ->create(['code' => '4200', 'name' => 'Sales Revenue']);

        $this->postedEntry('2026-07-10', [
            [$cash, '150.25', '0.00'],
            [$revenue, '0.00', '150.25'],
        ]);

        $payload = $this->app->make(TrialBalanceServiceInterface::class)->generate()->toArray();

        $this->assertSame('150.25', $payload['total_debit']);
        $this->assertSame('150.25', $payload['total_credit']);
        $this->assertTrue($payload['is_balanced']);
        $this->assertNull($payload['start_date']);
        $this->assertNull($payload['end_date']);
        $this->assertSame([
            'account_id' => $cash->id,
            'account_code' => '1110',
            'account_name' => 'Cash',
            'normal_balance' => 'debit',
            'total_debit' => '150.25',
            'total_credit' => '0.00',
            'current_balance' => '150.25',
        ], $payload['rows'][0]);
    }

    public function test_rows_use_one_aggregate_query(): void
    {
        $cash = Account::factory()->create(['code' => '1110']);
        $revenue = Account::factory()->normalBalance(NormalBalance::Credit)->create(['code' => '4200']);

        foreach (range(1, 4) as $i) {
            $this->postedEntry('2026-07-10', [
                [$cash, '10.00', '0.00'],
                [$revenue, '0.00', '10.00'],
            ]);
        }

        $repository = $this->app->make(TrialBalanceRepositoryInterface::class);

        DB::enableQueryLog();
        $repository->rows();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'Trial balance must be computed with a single aggregate query.');
        $this->assertMatchesRegularExpression('/sum\(/i', $queries[0]['query']);
        $this->assertMatchesRegularExpression('/group by/i', $queries[0]['query']);
    }

    public function test_service_depends_on_the_repository_interface(): void
    {
        $parameterTypes = array_map(
            function ($parameter): string {
                $type = $parameter->getType();

                $this->assertInstanceOf(ReflectionNamedType::class, $type);

                return $type->getName();
            },
            (new ReflectionClass(TrialBalanceService::class))->getConstructor()->getParameters(),
        );

        $this->assertSame([TrialBalanceRepositoryInterface::class], $parameterTypes);
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
