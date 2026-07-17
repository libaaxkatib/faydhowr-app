<?php

namespace Database\Factories;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Models\AccountingPeriod;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AccountingPeriod>
 */
class AccountingPeriodFactory extends Factory
{
    protected $model = AccountingPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::create(2000, 1, 1)->addMonths(fake()->unique()->numberBetween(0, 2000));

        return [
            'name' => $start->format('F Y'),
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->endOfMonth()->toDateString(),
            'status' => AccountingPeriodStatus::Open,
            'closed_at' => null,
            'closed_by' => null,
        ];
    }

    public function spanning(string $startDate, string $endDate): static
    {
        return $this->state(fn (): array => [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    public function closed(?Admin $admin = null): static
    {
        return $this->state(fn (): array => [
            'status' => AccountingPeriodStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $admin?->id,
        ]);
    }
}
