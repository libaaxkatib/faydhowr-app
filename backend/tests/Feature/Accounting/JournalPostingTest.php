<?php

namespace Tests\Feature\Accounting;

use App\Contracts\Accounting\AccountingManagerInterface;
use App\Contracts\Accounting\Repositories\JournalEntryRepositoryInterface;
use App\Contracts\Accounting\Services\JournalPostingServiceInterface;
use App\Enums\Accounting\JournalEntryStatus;
use App\Exceptions\Accounting\InsufficientJournalLinesException;
use App\Exceptions\Accounting\InvalidJournalStatusException;
use App\Exceptions\Accounting\JournalNotBalancedException;
use App\Exceptions\Accounting\PostingToGroupAccountException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Accounting\Services\JournalPostingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class JournalPostingTest extends TestCase
{
    use RefreshDatabase;

    public function test_posting_service_interface_resolves_to_a_singleton_posting_service(): void
    {
        $service = $this->app->make(JournalPostingServiceInterface::class);

        $this->assertInstanceOf(JournalPostingService::class, $service);
        $this->assertSame($service, $this->app->make(JournalPostingServiceInterface::class));
    }

    public function test_balanced_draft_journal_posts_successfully(): void
    {
        $entry = $this->balancedDraftEntry();

        $posted = $this->app->make(JournalPostingServiceInterface::class)->post($entry);

        $this->assertSame(JournalEntryStatus::Posted, $posted->status);
        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->id,
            'status' => JournalEntryStatus::Posted->value,
        ]);
    }

    public function test_posting_is_exposed_through_the_accounting_manager(): void
    {
        $entry = $this->balancedDraftEntry();

        $posted = $this->app->make(AccountingManagerInterface::class)
            ->journalEntries()
            ->post($entry);

        $this->assertSame(JournalEntryStatus::Posted, $posted->status);
    }

    public function test_posting_happens_inside_a_database_transaction_through_the_repository(): void
    {
        $spy = new class($this->app->make(JournalEntryRepositoryInterface::class)) implements JournalEntryRepositoryInterface
        {
            public ?int $transactionLevelAtMarkAsPosted = null;

            public function __construct(private JournalEntryRepositoryInterface $inner) {}

            public function findById(int $id): ?JournalEntry
            {
                return $this->inner->findById($id);
            }

            public function findByEntryNumber(string $entryNumber): ?JournalEntry
            {
                return $this->inner->findByEntryNumber($entryNumber);
            }

            public function latest(int $limit): Collection
            {
                return $this->inner->latest($limit);
            }

            public function markAsPosted(JournalEntry $entry): JournalEntry
            {
                $this->transactionLevelAtMarkAsPosted = DB::transactionLevel();

                return $this->inner->markAsPosted($entry);
            }
        };

        $this->app->instance(JournalEntryRepositoryInterface::class, $spy);

        $entry = $this->balancedDraftEntry();
        $baselineTransactionLevel = DB::transactionLevel();

        $this->app->make(JournalPostingServiceInterface::class)->post($entry);

        $this->assertNotNull(
            $spy->transactionLevelAtMarkAsPosted,
            'Posting must persist the status change through the repository.',
        );
        $this->assertGreaterThan(
            $baselineTransactionLevel,
            $spy->transactionLevelAtMarkAsPosted,
            'The repository must be called inside the posting transaction.',
        );
    }

    public function test_unbalanced_journal_is_rejected_and_stays_draft(): void
    {
        $entry = JournalEntry::factory()->create();
        JournalEntryLine::factory()->debit('500.00')->create(['journal_entry_id' => $entry->id]);
        JournalEntryLine::factory()->credit('300.00')->create(['journal_entry_id' => $entry->id]);

        try {
            $this->app->make(JournalPostingServiceInterface::class)->post($entry);

            $this->fail('An unbalanced journal entry must not post.');
        } catch (JournalNotBalancedException $exception) {
            $this->assertStringContainsString('500.00', $exception->getMessage());
            $this->assertStringContainsString('300.00', $exception->getMessage());
        }

        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->id,
            'status' => JournalEntryStatus::Draft->value,
        ]);
    }

    public function test_zero_amount_journal_is_rejected(): void
    {
        $entry = JournalEntry::factory()->create();
        JournalEntryLine::factory()->debit('0.00')->create(['journal_entry_id' => $entry->id]);
        JournalEntryLine::factory()->credit('0.00')->create(['journal_entry_id' => $entry->id]);

        $this->expectException(JournalNotBalancedException::class);
        $this->expectExceptionMessage('greater than zero');

        $this->app->make(JournalPostingServiceInterface::class)->post($entry);
    }

    public function test_only_draft_entries_may_be_posted(): void
    {
        foreach ([JournalEntryStatus::Posted, JournalEntryStatus::Cancelled] as $status) {
            $entry = JournalEntry::factory()->status($status)->create();

            try {
                $this->app->make(JournalPostingServiceInterface::class)->post($entry);

                $this->fail("A {$status->value} entry must not post.");
            } catch (InvalidJournalStatusException $exception) {
                $this->assertStringContainsString($status->value, $exception->getMessage());
            }

            $this->assertDatabaseHas('journal_entries', [
                'id' => $entry->id,
                'status' => $status->value,
            ]);
        }
    }

    public function test_posting_to_a_group_account_is_rejected(): void
    {
        $group = Account::factory()->group()->create(['code' => '1000']);
        $entry = $this->balancedDraftEntry();
        $entry->lines()->first()->update(['account_id' => $group->id]);

        $this->expectException(PostingToGroupAccountException::class);
        $this->expectExceptionMessage('1000');

        $this->app->make(JournalPostingServiceInterface::class)->post($entry);
    }

    public function test_at_least_two_lines_are_required(): void
    {
        $entry = JournalEntry::factory()->create();
        JournalEntryLine::factory()->debit('100.00')->create(['journal_entry_id' => $entry->id]);

        $this->expectException(InsufficientJournalLinesException::class);
        $this->expectExceptionMessage('at least two lines');

        $this->app->make(JournalPostingServiceInterface::class)->post($entry);
    }

    public function test_posting_service_depends_on_the_repository_interface(): void
    {
        $parameterTypes = array_map(
            function ($parameter): string {
                $type = $parameter->getType();

                $this->assertInstanceOf(ReflectionNamedType::class, $type);

                return $type->getName();
            },
            (new ReflectionClass(JournalPostingService::class))->getConstructor()->getParameters(),
        );

        $this->assertSame([JournalEntryRepositoryInterface::class], $parameterTypes);
    }

    private function balancedDraftEntry(): JournalEntry
    {
        $entry = JournalEntry::factory()->create();

        JournalEntryLine::factory()->debit('750.00')->create(['journal_entry_id' => $entry->id]);
        JournalEntryLine::factory()->credit('750.00')->create(['journal_entry_id' => $entry->id]);

        return $entry;
    }
}
