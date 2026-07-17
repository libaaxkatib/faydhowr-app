<?php

namespace Tests\Feature\Report;

use App\Contracts\Dashboard\DashboardQueryServiceInterface;
use App\Contracts\Reports\ReportManagerInterface;
use App\Contracts\Reports\Services\RevenueReportServiceInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\DataTransferObjects\Reports\RevenueReportData;
use App\Enums\DashboardDateFilter;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use App\Repositories\Reports\PaymentReportRepository;
use App\Services\Reports\Services\RevenueReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class RevenueReportTest extends TestCase
{
    use RefreshDatabase;

    private int $paymentSequence = 0;

    private ?Order $order = null;

    public function test_revenue_report_service_resolves_through_the_container_and_manager(): void
    {
        $service = $this->app->make(RevenueReportServiceInterface::class);

        $this->assertInstanceOf(RevenueReportService::class, $service);
        $this->assertSame(
            $service,
            $this->app->make(ReportManagerInterface::class)->revenueReports(),
        );
    }

    public function test_revenue_report_owns_the_payment_report_repository_dependency(): void
    {
        $parameters = (new ReflectionClass(RevenueReportService::class))
            ->getConstructor()
            ->getParameters();

        $this->assertCount(1, $parameters);

        $type = $parameters[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(PaymentReportRepository::class, $type->getName());
    }

    public function test_revenue_report_uses_the_payment_report_repository_summary(): void
    {
        $this->mock(PaymentReportRepository::class, function ($mock): void {
            $mock->shouldReceive('summary')
                ->once()
                ->withArgs(fn (NormalizedReportFilters $filters): bool => $filters->dateFrom() === null
                    && $filters->dateTo() === null)
                ->andReturn(['total_records' => 3, 'total_amount' => 123.45]);
        });

        $report = $this->app->make(RevenueReportServiceInterface::class)->generate();

        $this->assertSame(123.45, $report->totalRevenue);
        $this->assertSame(3, $report->totalPayments);
        $this->assertSame('all_time', $report->filter);
        $this->assertNull($report->startDate);
        $this->assertNull($report->endDate);
    }

    public function test_revenue_report_supports_every_dashboard_date_filter(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00:00'));
        $this->seedPayments();

        $service = $this->app->make(RevenueReportServiceInterface::class);

        foreach ($this->filterScenarios() as $label => $scenario) {
            $report = $service->generate(
                $scenario['filter'],
                $scenario['start'] ?? null,
                $scenario['end'] ?? null,
            );

            $this->assertSame(
                $scenario['revenue'],
                $report->totalRevenue,
                "Revenue for [{$label}] must reflect the filter.",
            );
            $this->assertSame(
                $scenario['payments'],
                $report->totalPayments,
                "Payment count for [{$label}] must reflect the filter.",
            );
            $this->assertSame($scenario['type'], $report->filter);
        }
    }

    public function test_revenue_report_matches_dashboard_revenue_figures_for_every_filter(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00:00'));
        $this->seedPayments();

        $service = $this->app->make(RevenueReportServiceInterface::class);
        $dashboard = $this->app->make(DashboardQueryServiceInterface::class);

        foreach ($this->filterScenarios() as $label => $scenario) {
            $report = $service->generate(
                $scenario['filter'],
                $scenario['start'] ?? null,
                $scenario['end'] ?? null,
            );

            $dashboard->applyDateFilter(
                $scenario['filter'],
                $scenario['start'] ?? null,
                $scenario['end'] ?? null,
            );

            $this->assertSame(
                $dashboard->revenueSummary()->total,
                $report->totalRevenue,
                "Report revenue for [{$label}] must equal the dashboard revenue.",
            );
            $this->assertSame(
                $dashboard->paymentSummary()->total,
                $report->totalPayments,
                "Report payment count for [{$label}] must equal the dashboard payment count.",
            );
        }
    }

    public function test_revenue_report_data_is_immutable_and_serializes_to_the_contract(): void
    {
        $this->assertTrue(
            (new ReflectionClass(RevenueReportData::class))->isReadOnly(),
            'RevenueReportData must be a readonly DTO.',
        );

        $report = new RevenueReportData(
            totalRevenue: 150.5,
            totalPayments: 4,
            filter: 'this_month',
            startDate: '2026-05-01T00:00:00+00:00',
            endDate: '2026-05-31T23:59:59+00:00',
            generatedAt: '2026-05-20T12:00:00+00:00',
        );

        $expected = [
            'total_revenue' => 150.5,
            'total_payments' => 4,
            'filter' => 'this_month',
            'start_date' => '2026-05-01T00:00:00+00:00',
            'end_date' => '2026-05-31T23:59:59+00:00',
            'generated_at' => '2026-05-20T12:00:00+00:00',
        ];

        $this->assertSame($expected, $report->toArray());
        $this->assertSame($expected, $report->jsonSerialize());
    }

    /**
     * Scenario table shared by the filter and dashboard-parity tests, based
     * on payments seeded relative to the frozen date 2026-05-20 12:00:00.
     *
     * @return array<string, array{filter: ?DashboardDateFilter, type: string, revenue: float, payments: int, start?: CarbonImmutable, end?: CarbonImmutable}>
     */
    private function filterScenarios(): array
    {
        return [
            'all_time' => [
                'filter' => null,
                'type' => 'all_time',
                'revenue' => 630.0,
                'payments' => 6,
            ],
            'today' => [
                'filter' => DashboardDateFilter::Today,
                'type' => 'today',
                'revenue' => 10.0,
                'payments' => 1,
            ],
            'yesterday' => [
                'filter' => DashboardDateFilter::Yesterday,
                'type' => 'yesterday',
                'revenue' => 20.0,
                'payments' => 1,
            ],
            'last_7_days' => [
                'filter' => DashboardDateFilter::Last7Days,
                'type' => 'last_7_days',
                'revenue' => 70.0,
                'payments' => 3,
            ],
            'last_30_days' => [
                'filter' => DashboardDateFilter::Last30Days,
                'type' => 'last_30_days',
                'revenue' => 150.0,
                'payments' => 4,
            ],
            'this_month' => [
                'filter' => DashboardDateFilter::ThisMonth,
                'type' => 'this_month',
                'revenue' => 150.0,
                'payments' => 4,
            ],
            'last_month' => [
                'filter' => DashboardDateFilter::LastMonth,
                'type' => 'last_month',
                'revenue' => 160.0,
                'payments' => 1,
            ],
            'custom_date_range' => [
                'filter' => DashboardDateFilter::CustomDateRange,
                'type' => 'custom_date_range',
                'revenue' => 160.0,
                'payments' => 1,
                'start' => CarbonImmutable::parse('2026-04-01'),
                'end' => CarbonImmutable::parse('2026-04-30'),
            ],
        ];
    }

    /**
     * Six payments relative to the frozen date 2026-05-20 12:00:00: one
     * today (10.00), one yesterday (20.00), one five days ago (40.00), one
     * on May 1st (80.00), one in April (160.00), and one in February
     * (320.00).
     */
    private function seedPayments(): void
    {
        $this->createPayment('10.00', CarbonImmutable::parse('2026-05-20 08:00:00'));
        $this->createPayment('20.00', CarbonImmutable::parse('2026-05-19 12:00:00'));
        $this->createPayment('40.00', CarbonImmutable::parse('2026-05-15 12:00:00'));
        $this->createPayment('80.00', CarbonImmutable::parse('2026-05-01 12:00:00'));
        $this->createPayment('160.00', CarbonImmutable::parse('2026-04-10 12:00:00'));
        $this->createPayment('320.00', CarbonImmutable::parse('2026-02-01 12:00:00'));
    }

    private function createPayment(string $amount, CarbonImmutable $createdAt): void
    {
        $payment = Payment::query()->create([
            'payment_number' => sprintf('PAY-2026-%06d', ++$this->paymentSequence),
            'customer_profile_id' => $this->payableOrder()->customer_profile_id,
            'payable_type' => Order::class,
            'payable_id' => $this->payableOrder()->id,
            'status' => PaymentStatus::Initialized,
            'amount' => $amount,
            'currency' => 'USD',
            'gateway' => 'manual',
        ]);

        $payment->forceFill(['created_at' => $createdAt])->save();
    }

    private function payableOrder(): Order
    {
        if ($this->order !== null) {
            return $this->order;
        }

        $profile = CustomerProfile::factory()->create();

        $quotation = Quotation::query()->create([
            'quotation_number' => 'QT-2026-000001',
            'customer_profile_id' => $profile->id,
            'status' => QuotationStatus::Accepted,
            'currency' => 'USD',
            'subtotal' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total_amount' => '100.00',
            'accepted_at' => now(),
        ]);

        return $this->order = Order::query()->create([
            'order_number' => 'ORD-2026-000001',
            'customer_profile_id' => $profile->id,
            'quotation_id' => $quotation->id,
            'status' => OrderStatus::PendingPayment,
            'subtotal' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total_amount' => '100.00',
        ]);
    }
}
