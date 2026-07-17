<?php

namespace Tests\Feature\Report;

use App\Contracts\Reports\ReportManagerInterface;
use App\Contracts\Reports\Services\CustomerReportServiceInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\DataTransferObjects\Reports\CustomerReportData;
use App\Enums\DashboardDateFilter;
use App\Models\CustomerProfile;
use App\Repositories\Reports\CustomerReportRepository;
use App\Services\Reports\Services\CustomerReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class CustomerReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_report_service_resolves_through_the_container_and_manager(): void
    {
        $service = $this->app->make(CustomerReportServiceInterface::class);

        $this->assertInstanceOf(CustomerReportService::class, $service);
        $this->assertSame(
            $service,
            $this->app->make(ReportManagerInterface::class)->customerReports(),
        );
    }

    public function test_customer_report_owns_the_customer_report_repository_dependency(): void
    {
        $parameters = (new ReflectionClass(CustomerReportService::class))
            ->getConstructor()
            ->getParameters();

        $this->assertCount(1, $parameters);

        $type = $parameters[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(CustomerReportRepository::class, $type->getName());
    }

    public function test_customer_report_uses_the_customer_report_repository_summary(): void
    {
        $this->mock(CustomerReportRepository::class, function ($mock): void {
            $mock->shouldReceive('summary')
                ->twice()
                ->withArgs(fn (NormalizedReportFilters $filters): bool => $filters->dateFrom() === null
                    && $filters->dateTo() === null
                    && $filters->status() === null)
                ->andReturn(['total_records' => 10]);
            $mock->shouldReceive('summary')
                ->once()
                ->withArgs(fn (NormalizedReportFilters $filters): bool => $filters->status() === 'active_customer')
                ->andReturn(['total_records' => 4]);
            $mock->shouldReceive('summary')
                ->once()
                ->withArgs(fn (NormalizedReportFilters $filters): bool => $filters->status() === 'lead')
                ->andReturn(['total_records' => 6]);
        });

        $report = $this->app->make(CustomerReportServiceInterface::class)->generate();

        $this->assertSame(10, $report->totalCustomers);
        $this->assertSame(4, $report->activeCustomers);
        $this->assertSame(6, $report->inactiveCustomers);
        $this->assertSame(10, $report->newCustomers);
        $this->assertSame('all_time', $report->filter);
        $this->assertNull($report->startDate);
        $this->assertNull($report->endDate);
    }

    public function test_customer_report_supports_every_dashboard_date_filter(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00:00'));
        $this->seedCustomers();

        $service = $this->app->make(CustomerReportServiceInterface::class);

        foreach ($this->filterScenarios() as $label => $scenario) {
            $report = $service->generate(
                $scenario['filter'],
                $scenario['start'] ?? null,
                $scenario['end'] ?? null,
            );

            $this->assertSame(
                $scenario['counts'],
                [
                    $report->totalCustomers,
                    $report->activeCustomers,
                    $report->inactiveCustomers,
                    $report->newCustomers,
                ],
                "Customer counts for [{$label}] must reflect the filter.",
            );
            $this->assertSame($scenario['type'], $report->filter);
        }
    }

    public function test_customer_report_values_remain_consistent_with_repository_results(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00:00'));
        $this->seedCustomers();

        $service = $this->app->make(CustomerReportServiceInterface::class);
        $repository = $this->app->make(CustomerReportRepository::class);

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

            $this->assertSame(
                $repository->summary(new NormalizedReportFilters(dateTo: $dateTo))['total_records'],
                $report->totalCustomers,
                "Report total for [{$label}] must equal the repository summary.",
            );
            $this->assertSame(
                $repository->summary(new NormalizedReportFilters(dateTo: $dateTo, status: 'active_customer'))['total_records'],
                $report->activeCustomers,
                "Active count for [{$label}] must equal the repository summary.",
            );
            $this->assertSame(
                $repository->summary(new NormalizedReportFilters(dateTo: $dateTo, status: 'lead'))['total_records'],
                $report->inactiveCustomers,
                "Inactive count for [{$label}] must equal the repository summary.",
            );
            $this->assertSame(
                $repository->summary(new NormalizedReportFilters(dateFrom: $dateFrom, dateTo: $dateTo))['total_records'],
                $report->newCustomers,
                "New count for [{$label}] must equal the repository summary.",
            );
        }
    }

    public function test_customer_report_data_is_immutable_and_serializes_to_the_contract(): void
    {
        $this->assertTrue(
            (new ReflectionClass(CustomerReportData::class))->isReadOnly(),
            'CustomerReportData must be a readonly DTO.',
        );

        $report = new CustomerReportData(
            totalCustomers: 6,
            activeCustomers: 3,
            inactiveCustomers: 3,
            newCustomers: 4,
            filter: 'this_month',
            startDate: '2026-05-01T00:00:00+00:00',
            endDate: '2026-05-31T23:59:59+00:00',
            generatedAt: '2026-05-20T12:00:00+00:00',
        );

        $expected = [
            'total_customers' => 6,
            'active_customers' => 3,
            'inactive_customers' => 3,
            'new_customers' => 4,
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
     * based on customers seeded relative to the frozen date 2026-05-20
     * 12:00:00. Counts are [total, active, inactive, new], where total,
     * active, and inactive are measured as of the end of the range and new
     * is measured within the range.
     *
     * @return array<string, array{filter: ?DashboardDateFilter, type: string, counts: array{int, int, int, int}, start?: CarbonImmutable, end?: CarbonImmutable}>
     */
    private function filterScenarios(): array
    {
        return [
            'all_time' => [
                'filter' => null,
                'type' => 'all_time',
                'counts' => [6, 3, 3, 6],
            ],
            'today' => [
                'filter' => DashboardDateFilter::Today,
                'type' => 'today',
                'counts' => [6, 3, 3, 1],
            ],
            'yesterday' => [
                'filter' => DashboardDateFilter::Yesterday,
                'type' => 'yesterday',
                'counts' => [5, 2, 3, 1],
            ],
            'last_7_days' => [
                'filter' => DashboardDateFilter::Last7Days,
                'type' => 'last_7_days',
                'counts' => [6, 3, 3, 3],
            ],
            'last_30_days' => [
                'filter' => DashboardDateFilter::Last30Days,
                'type' => 'last_30_days',
                'counts' => [6, 3, 3, 4],
            ],
            'this_month' => [
                'filter' => DashboardDateFilter::ThisMonth,
                'type' => 'this_month',
                'counts' => [6, 3, 3, 4],
            ],
            'last_month' => [
                'filter' => DashboardDateFilter::LastMonth,
                'type' => 'last_month',
                'counts' => [2, 1, 1, 1],
            ],
            'custom_date_range' => [
                'filter' => DashboardDateFilter::CustomDateRange,
                'type' => 'custom_date_range',
                'counts' => [2, 1, 1, 2],
                'start' => CarbonImmutable::parse('2026-02-01'),
                'end' => CarbonImmutable::parse('2026-04-30'),
            ],
        ];
    }

    /**
     * Six customers relative to the frozen date 2026-05-20 12:00:00: an
     * active customer today, a lead yesterday, an active customer five days
     * ago, a lead on May 1st, an active customer in April, and a lead in
     * February.
     */
    private function seedCustomers(): void
    {
        $this->createCustomer('active_customer', CarbonImmutable::parse('2026-05-20 08:00:00'));
        $this->createCustomer('lead', CarbonImmutable::parse('2026-05-19 12:00:00'));
        $this->createCustomer('active_customer', CarbonImmutable::parse('2026-05-15 12:00:00'));
        $this->createCustomer('lead', CarbonImmutable::parse('2026-05-01 12:00:00'));
        $this->createCustomer('active_customer', CarbonImmutable::parse('2026-04-10 12:00:00'));
        $this->createCustomer('lead', CarbonImmutable::parse('2026-02-01 12:00:00'));
    }

    private function createCustomer(string $classification, CarbonImmutable $createdAt): void
    {
        $factory = CustomerProfile::factory();

        if ($classification === 'active_customer') {
            $factory = $factory->activeCustomer();
        }

        $factory->create()->forceFill(['created_at' => $createdAt])->save();
    }
}
