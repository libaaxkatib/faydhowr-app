<?php

namespace Tests\Feature\Report;

use App\Contracts\Reports\ReportManagerInterface;
use App\Contracts\Reports\Services\InventoryReportServiceInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\DataTransferObjects\Reports\InventoryReportData;
use App\Enums\DashboardDateFilter;
use App\Models\Product;
use App\Repositories\Reports\InventoryReportRepository;
use App\Services\Reports\Services\InventoryReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class InventoryReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_report_service_resolves_through_the_container_and_manager(): void
    {
        $service = $this->app->make(InventoryReportServiceInterface::class);

        $this->assertInstanceOf(InventoryReportService::class, $service);
        $this->assertSame(
            $service,
            $this->app->make(ReportManagerInterface::class)->inventoryReports(),
        );
    }

    public function test_inventory_report_owns_the_inventory_report_repository_dependency(): void
    {
        $parameters = (new ReflectionClass(InventoryReportService::class))
            ->getConstructor()
            ->getParameters();

        $this->assertCount(1, $parameters);

        $type = $parameters[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(InventoryReportRepository::class, $type->getName());
    }

    public function test_inventory_report_uses_the_inventory_report_repository(): void
    {
        $this->mock(InventoryReportRepository::class, function ($mock): void {
            $mock->shouldReceive('stockLevelSummary')
                ->once()
                ->withArgs(fn (NormalizedReportFilters $filters): bool => $filters->dateFrom() === null
                    && $filters->dateTo() === null)
                ->andReturn(['in_stock' => 5, 'low_stock' => 3, 'out_of_stock' => 1]);
            $mock->shouldNotReceive('summary');
        });

        $report = $this->app->make(InventoryReportServiceInterface::class)->generate();

        $this->assertSame(9, $report->totalProducts);
        $this->assertSame(5, $report->inStockProducts);
        $this->assertSame(3, $report->lowStockProducts);
        $this->assertSame(1, $report->outOfStockProducts);
        $this->assertSame('all_time', $report->filter);
        $this->assertNull($report->startDate);
        $this->assertNull($report->endDate);
    }

    public function test_inventory_report_supports_every_dashboard_date_filter(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00:00'));
        $this->seedProducts();

        $service = $this->app->make(InventoryReportServiceInterface::class);

        foreach ($this->filterScenarios() as $label => $scenario) {
            $report = $service->generate(
                $scenario['filter'],
                $scenario['start'] ?? null,
                $scenario['end'] ?? null,
            );

            $this->assertSame(
                $scenario['counts'],
                [
                    $report->totalProducts,
                    $report->inStockProducts,
                    $report->lowStockProducts,
                    $report->outOfStockProducts,
                ],
                "Inventory counts for [{$label}] must reflect the filter.",
            );
            $this->assertSame(
                $report->totalProducts,
                $report->inStockProducts + $report->lowStockProducts + $report->outOfStockProducts,
                "Stock buckets for [{$label}] must sum to the total.",
            );
            $this->assertSame($scenario['type'], $report->filter);
        }
    }

    public function test_inventory_report_values_remain_consistent_with_repository_results(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00:00'));
        $this->seedProducts();

        $service = $this->app->make(InventoryReportServiceInterface::class);
        $repository = $this->app->make(InventoryReportRepository::class);

        foreach ($this->filterScenarios() as $label => $scenario) {
            $report = $service->generate(
                $scenario['filter'],
                $scenario['start'] ?? null,
                $scenario['end'] ?? null,
            );

            [$dateFrom, $dateTo] = $scenario['filter']?->dateRange(
                $scenario['start'] ?? null,
                $scenario['end'] ?? null,
            ) ?? [null, null];

            $filters = new NormalizedReportFilters(dateFrom: $dateFrom, dateTo: $dateTo);
            $stockLevels = $repository->stockLevelSummary($filters);

            $this->assertSame(
                $repository->summary($filters)['total_records'],
                $report->totalProducts,
                "Report total for [{$label}] must equal the repository summary.",
            );
            $this->assertSame(
                [$stockLevels['in_stock'], $stockLevels['low_stock'], $stockLevels['out_of_stock']],
                [$report->inStockProducts, $report->lowStockProducts, $report->outOfStockProducts],
                "Stock buckets for [{$label}] must equal the repository stock level summary.",
            );
        }
    }

    public function test_inventory_report_data_is_immutable_and_serializes_to_the_contract(): void
    {
        $this->assertTrue(
            (new ReflectionClass(InventoryReportData::class))->isReadOnly(),
            'InventoryReportData must be a readonly DTO.',
        );

        $report = new InventoryReportData(
            totalProducts: 6,
            inStockProducts: 2,
            lowStockProducts: 2,
            outOfStockProducts: 2,
            filter: 'this_month',
            startDate: '2026-05-01T00:00:00+00:00',
            endDate: '2026-05-31T23:59:59+00:00',
            generatedAt: '2026-05-20T12:00:00+00:00',
        );

        $expected = [
            'total_products' => 6,
            'in_stock_products' => 2,
            'low_stock_products' => 2,
            'out_of_stock_products' => 2,
            'filter' => 'this_month',
            'start_date' => '2026-05-01T00:00:00+00:00',
            'end_date' => '2026-05-31T23:59:59+00:00',
            'generated_at' => '2026-05-20T12:00:00+00:00',
        ];

        $this->assertSame($expected, $report->toArray());
        $this->assertSame($expected, $report->jsonSerialize());
    }

    /**
     * Scenario table shared by the filter and repository-consistency tests,
     * based on products seeded relative to the frozen date 2026-05-20
     * 12:00:00. Counts are [total, in stock, low stock, out of stock].
     *
     * @return array<string, array{filter: ?DashboardDateFilter, type: string, counts: array{int, int, int, int}, start?: CarbonImmutable, end?: CarbonImmutable}>
     */
    private function filterScenarios(): array
    {
        return [
            'all_time' => [
                'filter' => null,
                'type' => 'all_time',
                'counts' => [6, 2, 2, 2],
            ],
            'today' => [
                'filter' => DashboardDateFilter::Today,
                'type' => 'today',
                'counts' => [1, 1, 0, 0],
            ],
            'yesterday' => [
                'filter' => DashboardDateFilter::Yesterday,
                'type' => 'yesterday',
                'counts' => [1, 0, 1, 0],
            ],
            'last_7_days' => [
                'filter' => DashboardDateFilter::Last7Days,
                'type' => 'last_7_days',
                'counts' => [3, 1, 1, 1],
            ],
            'last_30_days' => [
                'filter' => DashboardDateFilter::Last30Days,
                'type' => 'last_30_days',
                'counts' => [4, 2, 1, 1],
            ],
            'this_month' => [
                'filter' => DashboardDateFilter::ThisMonth,
                'type' => 'this_month',
                'counts' => [4, 2, 1, 1],
            ],
            'last_month' => [
                'filter' => DashboardDateFilter::LastMonth,
                'type' => 'last_month',
                'counts' => [1, 0, 1, 0],
            ],
            'custom_date_range' => [
                'filter' => DashboardDateFilter::CustomDateRange,
                'type' => 'custom_date_range',
                'counts' => [2, 0, 1, 1],
                'start' => CarbonImmutable::parse('2026-02-01'),
                'end' => CarbonImmutable::parse('2026-04-30'),
            ],
        ];
    }

    /**
     * Six products relative to the frozen date 2026-05-20 12:00:00: in
     * stock today, low stock yesterday, out of stock five days ago, in
     * stock on May 1st, low stock in April, and out of stock in February.
     */
    private function seedProducts(): void
    {
        $this->createProduct(50, 10, CarbonImmutable::parse('2026-05-20 08:00:00'));
        $this->createProduct(5, 10, CarbonImmutable::parse('2026-05-19 12:00:00'));
        $this->createProduct(0, 10, CarbonImmutable::parse('2026-05-15 12:00:00'));
        $this->createProduct(20, 5, CarbonImmutable::parse('2026-05-01 12:00:00'));
        $this->createProduct(3, 5, CarbonImmutable::parse('2026-04-10 12:00:00'));
        $this->createProduct(0, 5, CarbonImmutable::parse('2026-02-01 12:00:00'));
    }

    private function createProduct(int $currentStock, int $lowStockThreshold, CarbonImmutable $createdAt): void
    {
        Product::factory()
            ->create([
                'current_stock' => $currentStock,
                'low_stock_threshold' => $lowStockThreshold,
            ])
            ->forceFill(['created_at' => $createdAt])
            ->save();
    }
}
