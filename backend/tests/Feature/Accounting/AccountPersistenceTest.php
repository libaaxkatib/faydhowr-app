<?php

namespace Tests\Feature\Accounting;

use App\Contracts\Accounting\Repositories\AccountRepositoryInterface;
use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountStatus;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Models\Account;
use App\Repositories\Accounting\AccountRepository;
use App\Services\Accounting\Services\ChartOfAccountService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class AccountPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_table_migrates_with_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('accounts'));
        $this->assertTrue(Schema::hasColumns('accounts', [
            'id',
            'code',
            'name',
            'account_type',
            'account_category',
            'parent_account_id',
            'is_group',
            'normal_balance',
            'status',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_account_code_must_be_unique(): void
    {
        Account::factory()->create(['code' => '1100']);

        $this->expectException(QueryException::class);

        Account::factory()->create(['code' => '1100']);
    }

    public function test_account_model_casts_enum_and_boolean_attributes(): void
    {
        $account = Account::factory()->create([
            'account_type' => AccountType::Bank,
            'account_category' => AccountCategory::Assets,
            'is_group' => false,
            'normal_balance' => NormalBalance::Debit,
            'status' => AccountStatus::Active,
        ]);

        $account->refresh();

        $this->assertSame(AccountType::Bank, $account->account_type);
        $this->assertSame(AccountCategory::Assets, $account->account_category);
        $this->assertFalse($account->is_group);
        $this->assertSame(NormalBalance::Debit, $account->normal_balance);
        $this->assertSame(AccountStatus::Active, $account->status);
    }

    public function test_account_parent_and_children_relationships(): void
    {
        $parent = Account::factory()->group()->create(['code' => '1000']);
        $cash = Account::factory()->childOf($parent)->create(['code' => '1100']);
        $bank = Account::factory()->childOf($parent)->type(AccountType::Bank)->create(['code' => '1200']);

        $this->assertTrue($cash->parent->is($parent));
        $this->assertTrue($bank->parent->is($parent));
        $this->assertNull($parent->parent);

        $children = $parent->children;

        $this->assertCount(2, $children);
        $this->assertTrue($children->contains($cash));
        $this->assertTrue($children->contains($bank));
    }

    public function test_account_repository_interface_resolves_to_a_singleton_repository(): void
    {
        $repository = $this->app->make(AccountRepositoryInterface::class);

        $this->assertInstanceOf(AccountRepository::class, $repository);
        $this->assertSame($repository, $this->app->make(AccountRepositoryInterface::class));
    }

    public function test_repository_finds_accounts_by_id_and_code(): void
    {
        $account = Account::factory()->create(['code' => '4100']);
        $repository = $this->app->make(AccountRepositoryInterface::class);

        $this->assertTrue($repository->findById($account->id)?->is($account));
        $this->assertTrue($repository->findByCode('4100')?->is($account));

        $this->assertNull($repository->findById($account->id + 1));
        $this->assertNull($repository->findByCode('9999'));
    }

    public function test_chart_of_account_service_receives_the_repository_interface(): void
    {
        $parameterTypes = array_map(
            function ($parameter): string {
                $type = $parameter->getType();

                $this->assertInstanceOf(ReflectionNamedType::class, $type);

                return $type->getName();
            },
            (new ReflectionClass(ChartOfAccountService::class))->getConstructor()->getParameters(),
        );

        $this->assertContains(AccountRepositoryInterface::class, $parameterTypes);
        $this->assertNotContains(
            AccountRepository::class,
            $parameterTypes,
            'ChartOfAccountService must depend on the repository interface, not the concrete repository.',
        );
    }
}
