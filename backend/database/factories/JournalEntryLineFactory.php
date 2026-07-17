<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalEntryLine>
 */
class JournalEntryLineFactory extends Factory
{
    protected $model = JournalEntryLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'journal_entry_id' => JournalEntry::factory(),
            'account_id' => Account::factory(),
            'debit' => '100.00',
            'credit' => '0.00',
            'description' => fake()->sentence(),
        ];
    }

    public function debit(string $amount): static
    {
        return $this->state(fn (): array => [
            'debit' => $amount,
            'credit' => '0.00',
        ]);
    }

    public function credit(string $amount): static
    {
        return $this->state(fn (): array => [
            'debit' => '0.00',
            'credit' => $amount,
        ]);
    }
}
