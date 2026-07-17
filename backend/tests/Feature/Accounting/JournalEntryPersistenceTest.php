<?php

namespace Tests\Feature\Accounting;

use App\Contracts\Accounting\Repositories\JournalEntryRepositoryInterface;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Account;
use App\Models\Admin;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Repositories\Accounting\JournalEntryRepository;
use App\Services\Accounting\Services\JournalEntryService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class JournalEntryPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_journal_entry_tables_migrate_with_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('journal_entries'));
        $this->assertTrue(Schema::hasColumns('journal_entries', [
            'id',
            'entry_number',
            'reference_type',
            'reference_id',
            'description',
            'entry_date',
            'status',
            'created_by',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('journal_entry_lines'));
        $this->assertTrue(Schema::hasColumns('journal_entry_lines', [
            'id',
            'journal_entry_id',
            'account_id',
            'debit',
            'credit',
            'description',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_entry_number_must_be_unique(): void
    {
        JournalEntry::factory()->create(['entry_number' => 'JE-000001']);

        $this->expectException(QueryException::class);

        JournalEntry::factory()->create(['entry_number' => 'JE-000001']);
    }

    public function test_journal_entry_casts_status_and_entry_date(): void
    {
        $entry = JournalEntry::factory()->posted()->create([
            'entry_date' => '2026-07-17',
        ]);

        $entry->refresh();

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame('2026-07-17', $entry->entry_date->toDateString());
    }

    public function test_journal_entry_line_casts_amounts_to_decimal_strings(): void
    {
        $line = JournalEntryLine::factory()->debit('1250.50')->create();

        $line->refresh();

        $this->assertSame('1250.50', $line->debit);
        $this->assertSame('0.00', $line->credit);
    }

    public function test_journal_entry_relationships(): void
    {
        $admin = Admin::factory()->create();
        $entry = JournalEntry::factory()->createdBy($admin)->create();
        $cash = Account::factory()->create(['code' => '1110']);
        $revenue = Account::factory()->create(['code' => '4200']);

        $debitLine = JournalEntryLine::factory()->debit('500.00')->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
        ]);
        $creditLine = JournalEntryLine::factory()->credit('500.00')->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenue->id,
        ]);

        $lines = $entry->lines;

        $this->assertCount(2, $lines);
        $this->assertTrue($lines->contains($debitLine));
        $this->assertTrue($lines->contains($creditLine));

        $this->assertTrue($debitLine->journalEntry->is($entry));
        $this->assertTrue($debitLine->account->is($cash));
        $this->assertTrue($creditLine->account->is($revenue));
        $this->assertTrue($entry->createdBy->is($admin));
    }

    public function test_deleting_a_journal_entry_cascades_to_its_lines(): void
    {
        $entry = JournalEntry::factory()->create();
        JournalEntryLine::factory()->count(2)->create(['journal_entry_id' => $entry->id]);

        $entry->delete();

        $this->assertDatabaseCount('journal_entry_lines', 0);
    }

    public function test_journal_entry_repository_interface_resolves_to_a_singleton_repository(): void
    {
        $repository = $this->app->make(JournalEntryRepositoryInterface::class);

        $this->assertInstanceOf(JournalEntryRepository::class, $repository);
        $this->assertSame($repository, $this->app->make(JournalEntryRepositoryInterface::class));
    }

    public function test_repository_finds_entries_by_id_and_entry_number(): void
    {
        $entry = JournalEntry::factory()->create(['entry_number' => 'JE-100200']);
        $repository = $this->app->make(JournalEntryRepositoryInterface::class);

        $this->assertTrue($repository->findById($entry->id)?->is($entry));
        $this->assertTrue($repository->findByEntryNumber('JE-100200')?->is($entry));

        $this->assertNull($repository->findById($entry->id + 1));
        $this->assertNull($repository->findByEntryNumber('JE-999999'));
    }

    public function test_journal_entry_service_receives_the_repository_interface(): void
    {
        $parameterTypes = array_map(
            function ($parameter): string {
                $type = $parameter->getType();

                $this->assertInstanceOf(ReflectionNamedType::class, $type);

                return $type->getName();
            },
            (new ReflectionClass(JournalEntryService::class))->getConstructor()->getParameters(),
        );

        $this->assertContains(JournalEntryRepositoryInterface::class, $parameterTypes);
        $this->assertNotContains(
            JournalEntryRepository::class,
            $parameterTypes,
            'JournalEntryService must depend on the repository interface, not the concrete repository.',
        );
    }
}
