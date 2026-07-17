<?php

namespace Database\Factories;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountStatus;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('####'),
            'name' => fake()->words(3, true),
            'account_type' => AccountType::Cash,
            'account_category' => AccountCategory::Assets,
            'parent_account_id' => null,
            'is_group' => false,
            'normal_balance' => NormalBalance::Debit,
            'status' => AccountStatus::Active,
        ];
    }

    public function type(AccountType $type): static
    {
        return $this->state(fn (): array => [
            'account_type' => $type,
        ]);
    }

    public function category(AccountCategory $category): static
    {
        return $this->state(fn (): array => [
            'account_category' => $category,
        ]);
    }

    public function normalBalance(NormalBalance $normalBalance): static
    {
        return $this->state(fn (): array => [
            'normal_balance' => $normalBalance,
        ]);
    }

    public function group(): static
    {
        return $this->state(fn (): array => [
            'is_group' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'status' => AccountStatus::Inactive,
        ]);
    }

    public function childOf(Account $parent): static
    {
        return $this->state(fn (): array => [
            'parent_account_id' => $parent->id,
        ]);
    }
}
