<?php

namespace Tests\Feature\Report;

use App\Contracts\Reports\ReportManagerInterface;
use App\Contracts\Reports\Services\BookingReportServiceInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\DataTransferObjects\Reports\BookingReportData;
use App\Enums\BookingStatus;
use App\Enums\DashboardDateFilter;
use App\Enums\ServiceMode;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Repositories\Reports\BookingReportRepository;
use App\Services\Reports\Services\BookingReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class BookingReportTest extends TestCase
{
    use RefreshDatabase;

    private int $bookingSequence = 0;

    private ?Service $service = null;

    private ?int $serviceModeId = null;

    public function test_booking_report_service_resolves_through_the_container_and_manager(): void
    {
        $service = $this->app->make(BookingReportServiceInterface::class);

        $this->assertInstanceOf(BookingReportService::class, $service);
        $this->assertSame(
            $service,
            $this->app->make(ReportManagerInterface::class)->bookingReports(),
        );
    }

    public function test_booking_report_owns_the_booking_report_repository_dependency(): void
    {
        $parameters = (new ReflectionClass(BookingReportService::class))
            ->getConstructor()
            ->getParameters();

        $this->assertCount(1, $parameters);

        $type = $parameters[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(BookingReportRepository::class, $type->getName());
    }

    public function test_booking_report_uses_the_booking_report_repository_summary(): void
    {
        $expectedStatuses = [null, BookingStatus::Completed->value, BookingStatus::Cancelled->value];
        $summaries = [
            ['total_records' => 10],
            ['total_records' => 4],
            ['total_records' => 3],
        ];

        $this->mock(BookingReportRepository::class, function ($mock) use ($expectedStatuses, &$summaries): void {
            foreach ($expectedStatuses as $index => $status) {
                $mock->shouldReceive('summary')
                    ->once()
                    ->withArgs(fn (NormalizedReportFilters $filters): bool => $filters->dateFrom() === null
                        && $filters->dateTo() === null
                        && $filters->status() === $status)
                    ->andReturn($summaries[$index]);
            }
        });

        $report = $this->app->make(BookingReportServiceInterface::class)->generate();

        $this->assertSame(10, $report->totalBookings);
        $this->assertSame(4, $report->completedBookings);
        $this->assertSame(3, $report->cancelledBookings);
        $this->assertSame(3, $report->pendingBookings);
        $this->assertSame('all_time', $report->filter);
        $this->assertNull($report->startDate);
        $this->assertNull($report->endDate);
    }

    public function test_booking_report_supports_every_dashboard_date_filter(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00:00'));
        $this->seedBookings();

        $service = $this->app->make(BookingReportServiceInterface::class);

        foreach ($this->filterScenarios() as $label => $scenario) {
            $report = $service->generate(
                $scenario['filter'],
                $scenario['start'] ?? null,
                $scenario['end'] ?? null,
            );

            $this->assertSame(
                $scenario['counts'],
                [
                    $report->totalBookings,
                    $report->completedBookings,
                    $report->cancelledBookings,
                    $report->pendingBookings,
                ],
                "Booking counts for [{$label}] must reflect the filter.",
            );
            $this->assertSame($scenario['type'], $report->filter);
        }
    }

    public function test_booking_report_values_remain_consistent_with_repository_results(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00:00'));
        $this->seedBookings();

        $service = $this->app->make(BookingReportServiceInterface::class);
        $repository = $this->app->make(BookingReportRepository::class);

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
                $repository->summary(new NormalizedReportFilters(dateFrom: $dateFrom, dateTo: $dateTo))['total_records'],
                $report->totalBookings,
                "Report total for [{$label}] must equal the repository summary.",
            );
            $this->assertSame(
                $repository->summary(new NormalizedReportFilters(
                    dateFrom: $dateFrom,
                    dateTo: $dateTo,
                    status: BookingStatus::Completed->value,
                ))['total_records'],
                $report->completedBookings,
                "Completed count for [{$label}] must equal the repository summary.",
            );
            $this->assertSame(
                $repository->summary(new NormalizedReportFilters(
                    dateFrom: $dateFrom,
                    dateTo: $dateTo,
                    status: BookingStatus::Cancelled->value,
                ))['total_records'],
                $report->cancelledBookings,
                "Cancelled count for [{$label}] must equal the repository summary.",
            );
        }
    }

    public function test_booking_report_data_is_immutable_and_serializes_to_the_contract(): void
    {
        $this->assertTrue(
            (new ReflectionClass(BookingReportData::class))->isReadOnly(),
            'BookingReportData must be a readonly DTO.',
        );

        $report = new BookingReportData(
            totalBookings: 6,
            completedBookings: 2,
            cancelledBookings: 2,
            pendingBookings: 2,
            filter: 'this_month',
            startDate: '2026-05-01T00:00:00+00:00',
            endDate: '2026-05-31T23:59:59+00:00',
            generatedAt: '2026-05-20T12:00:00+00:00',
        );

        $expected = [
            'total_bookings' => 6,
            'completed_bookings' => 2,
            'cancelled_bookings' => 2,
            'pending_bookings' => 2,
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
     * based on bookings seeded relative to the frozen date 2026-05-20
     * 12:00:00. Counts are [total, completed, cancelled, pending].
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
                'counts' => [1, 0, 1, 0],
                'start' => CarbonImmutable::parse('2026-04-01'),
                'end' => CarbonImmutable::parse('2026-04-30'),
            ],
        ];
    }

    /**
     * Six bookings relative to the frozen date 2026-05-20 12:00:00: one
     * completed today, one cancelled yesterday, one submitted five days ago,
     * one completed on May 1st, one cancelled in April, and one submitted in
     * February.
     */
    private function seedBookings(): void
    {
        $this->createBooking(BookingStatus::Completed, CarbonImmutable::parse('2026-05-20 08:00:00'));
        $this->createBooking(BookingStatus::Cancelled, CarbonImmutable::parse('2026-05-19 12:00:00'));
        $this->createBooking(BookingStatus::Submitted, CarbonImmutable::parse('2026-05-15 12:00:00'));
        $this->createBooking(BookingStatus::Completed, CarbonImmutable::parse('2026-05-01 12:00:00'));
        $this->createBooking(BookingStatus::Cancelled, CarbonImmutable::parse('2026-04-10 12:00:00'));
        $this->createBooking(BookingStatus::Submitted, CarbonImmutable::parse('2026-02-01 12:00:00'));
    }

    private function createBooking(BookingStatus $status, CarbonImmutable $createdAt): void
    {
        $booking = Booking::query()->create([
            'booking_number' => sprintf('BK-2026-%06d', ++$this->bookingSequence),
            'customer_profile_id' => CustomerProfile::factory()->create()->id,
            'service_id' => $this->bookableService()->id,
            'service_mode_id' => $this->serviceModeId,
            'status' => $status,
            'requested_date' => now()->addWeek()->toDateString(),
            'requested_time_window' => '09:00-12:00',
            'address_snapshot' => [
                'line1' => 'KM4 Road',
                'city' => 'Mogadishu',
                'country_code' => 'SO',
            ],
            'cancelled_at' => $status === BookingStatus::Cancelled ? now() : null,
        ]);

        $booking->forceFill(['created_at' => $createdAt])->save();
    }

    private function bookableService(): Service
    {
        if ($this->service !== null) {
            return $this->service;
        }

        $category = ServiceCategory::query()->create([
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->service = Service::query()->create([
            'category_id' => $category->id,
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'currency' => 'USD',
            'requires_address' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->serviceModeId = (int) DB::table('service_modes')->insertGetId([
            'service_id' => $this->service->id,
            'mode' => ServiceMode::OneTime->value,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->service;
    }
}
