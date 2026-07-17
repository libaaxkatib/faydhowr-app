<?php

namespace App\Repositories\Accounting;

use App\Contracts\Accounting\Repositories\JournalEntryRepositoryInterface;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Collection;

class JournalEntryRepository implements JournalEntryRepositoryInterface
{
    public function findById(int $id): ?JournalEntry
    {
        return JournalEntry::query()->find($id);
    }

    public function findByEntryNumber(string $entryNumber): ?JournalEntry
    {
        return JournalEntry::query()->where('entry_number', $entryNumber)->first();
    }

    public function latest(int $limit): Collection
    {
        return JournalEntry::query()
            ->with('lines')
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function markAsPosted(JournalEntry $entry): JournalEntry
    {
        $entry->status = JournalEntryStatus::Posted;
        $entry->save();

        return $entry;
    }
}
