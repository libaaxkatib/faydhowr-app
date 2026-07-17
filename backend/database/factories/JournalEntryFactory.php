<?php

namespace Database\Factories;

use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Admin;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalEntry>
 */
class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entry_number' => 'JE-'.fake()->unique()->numerify('######'),
            'reference_type' => null,
            'reference_id' => null,
            'description' => fake()->sentence(),
            'entry_date' => now()->toDateString(),
            'status' => JournalEntryStatus::Draft,
            'created_by' => null,
        ];
    }

    public function status(JournalEntryStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
        ]);
    }

    public function posted(): static
    {
        return $this->status(JournalEntryStatus::Posted);
    }

    public function cancelled(): static
    {
        return $this->status(JournalEntryStatus::Cancelled);
    }

    public function createdBy(?Admin $admin = null): static
    {
        return $this->state(fn (): array => [
            'created_by' => $admin?->id ?? Admin::factory(),
        ]);
    }
}
