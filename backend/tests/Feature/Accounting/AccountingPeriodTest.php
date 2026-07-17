<?php

namespace Tests\Feature\Accounting;

use App\Contracts\Accounting\AccountingManagerInterface;
use App\Contracts\Accounting\Repositories\AccountingPeriodRepositoryInterface;
use App\Contracts\Accounting\Services\AccountingPeriodServiceInterface;
use App\Enums\Accounting\AccountingPeriodStatus;
use App\Exceptions\Accounting\InvalidAccountingPeriodException;
use App\Exceptions\Accounting\OverlappingAccountingPeriodException;
use App\Models\AccountingPeriod;
use App\Models\Admin;
use App\Repositories\Accounting\AccountingPeriodRepository;
use App\Services\Accounting\Services\AccountingPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class AccountingPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_periods_table_migrates_with_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('accounting_periods'));
        $this->assertTrue(Schema::hasColumns('accounting_periods', [
            'id',
            'name',
            'start_date',
            'end_date',
            'status',
            'closed_at',
            'closed_by',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_accounting_period_casts_status_and_dates(): void
    {
        $admin = Admin::factory()->create();
        $period = AccountingPeriod::factory()
            ->spanning('2026-07-01', '2026-07-31')
            ->closed($admin)
            ->create();

        $period->refresh();

        $this->assertSame(AccountingPeriodStatus::Closed, $period->status);
        $this->assertSame('2026-07-01', $period->start_date->toDateString());
        $this->assertSame('2026-07-31', $period->end_date->toDateString());
        $this->assertNotNull($period->closed_at);
        $this->assertTrue($period->closedBy->is($admin));
    }

    public function test_repository_interface_resolves_to_a_singleton_repository(): void
    {
        $repository = $this->app->make(AccountingPeriodRepositoryInterface::class);

        $this->assertInstanceOf(AccountingPeriodRepository::class, $repository);
        $this->assertSame($repository, $this->app->make(AccountingPeriodRepositoryInterface::class));
    }

    public function test_repository_finds_the_period_containing_a_date(): void
    {
        $period = AccountingPeriod::factory()->spanning('2026-07-01', '2026-07-31')->create();
        $repository = $this->app->make(AccountingPeriodRepositoryInterface::class);

        $this->assertTrue($repository->findByDate(Carbon::parse('2026-07-01'))?->is($period));
        $this->assertTrue($repository->findByDate(Carbon::parse('2026-07-15'))?->is($period));
        $this->assertTrue($repository->findByDate(Carbon::parse('2026-07-31'))?->is($period));
        $this->assertNull($repository->findByDate(Carbon::parse('2026-08-01')));
        $this->assertNull($repository->findByDate(Carbon::parse('2026-06-30')));
    }

    public function test_repository_detects_overlapping_ranges(): void
    {
        AccountingPeriod::factory()->spanning('2026-07-01', '2026-07-31')->create();
        $repository = $this->app->make(AccountingPeriodRepositoryInterface::class);

        $overlapping = [
            ['2026-07-01', '2026-07-31'],
            ['2026-06-15', '2026-07-01'],
            ['2026-07-31', '2026-08-15'],
            ['2026-07-10', '2026-07-20'],
            ['2026-06-01', '2026-08-31'],
        ];

        foreach ($overlapping as [$start, $end]) {
            $this->assertTrue(
                $repository->hasOverlap(Carbon::parse($start), Carbon::parse($end)),
                "[{$start} .. {$end}] must overlap the existing period.",
            );
        }

        $this->assertFalse($repository->hasOverlap(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')));
        $this->assertFalse($repository->hasOverlap(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')));
    }

    public function test_service_creates_an_open_period_through_the_manager(): void
    {
        $period = $this->app->make(AccountingManagerInterface::class)
            ->accountingPeriods()
            ->create('July 2026', Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $this->assertSame(AccountingPeriodStatus::Open, $period->status);
        $this->assertNull($period->closed_at);
        $this->assertDatabaseHas('accounting_periods', [
            'name' => 'July 2026',
            'status' => AccountingPeriodStatus::Open->value,
        ]);

        $period->refresh();

        $this->assertSame('2026-07-01', $period->start_date->toDateString());
        $this->assertSame('2026-07-31', $period->end_date->toDateString());
    }

    public function test_service_rejects_overlapping_periods(): void
    {
        $service = $this->app->make(AccountingPeriodServiceInterface::class);
        $service->create('July 2026', Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $this->expectException(OverlappingAccountingPeriodException::class);

        $service->create('Mid July 2026', Carbon::parse('2026-07-15'), Carbon::parse('2026-08-15'));
    }

    public function test_service_rejects_an_inverted_date_range(): void
    {
        $this->expectException(InvalidAccountingPeriodException::class);

        $this->app->make(AccountingPeriodServiceInterface::class)
            ->create('Broken', Carbon::parse('2026-07-31'), Carbon::parse('2026-07-01'));
    }

    public function test_service_finds_the_period_containing_a_date(): void
    {
        $period = AccountingPeriod::factory()->spanning('2026-07-01', '2026-07-31')->create();
        $service = $this->app->make(AccountingPeriodServiceInterface::class);

        $this->assertTrue($service->periodContaining(Carbon::parse('2026-07-15'))?->is($period));
        $this->assertNull($service->periodContaining(Carbon::parse('2026-09-01')));
    }

    public function test_service_depends_on_the_repository_interface(): void
    {
        $parameterTypes = array_map(
            function ($parameter): string {
                $type = $parameter->getType();

                $this->assertInstanceOf(ReflectionNamedType::class, $type);

                return $type->getName();
            },
            (new ReflectionClass(AccountingPeriodService::class))->getConstructor()->getParameters(),
        );

        $this->assertSame([AccountingPeriodRepositoryInterface::class], $parameterTypes);
    }
}
