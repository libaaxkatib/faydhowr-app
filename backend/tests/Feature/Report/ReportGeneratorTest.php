<?php

namespace Tests\Feature\Report;

use App\Actions\Report\GenerateReportAction;
use App\Actions\Report\NormalizeReportFiltersAction;
use App\Contracts\Reports\Generators\ReportGeneratorInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Exceptions\Reports\UnsupportedReportTypeException;
use App\Models\Admin;
use App\Models\Supplier;
use App\Services\Reports\Generators\BookingReportGenerator;
use App\Services\Reports\Generators\CustomerReportGenerator;
use App\Services\Reports\Generators\GoodsReceiptReportGenerator;
use App\Services\Reports\Generators\InventoryReportGenerator;
use App\Services\Reports\Generators\OrderReportGenerator;
use App\Services\Reports\Generators\PaymentReportGenerator;
use App\Services\Reports\Generators\PurchaseOrderReportGenerator;
use App\Services\Reports\Generators\QuotationReportGenerator;
use App\Services\Reports\Generators\StoreOrderReportGenerator;
use App\Services\Reports\Generators\SupplierReportGenerator;
use App\Services\Reports\ReportManager;
use App\Services\Reports\Services\BookingReportService;
use App\Services\Reports\Services\CustomerReportService;
use App\Services\Reports\Services\InventoryReportService;
use App\Services\Reports\Services\RevenueReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportGeneratorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, class-string<ReportGeneratorInterface>>
     */
    private const EXPECTED_GENERATORS = [
        'bookings' => BookingReportGenerator::class,
        'quotations' => QuotationReportGenerator::class,
        'orders' => OrderReportGenerator::class,
        'payments' => PaymentReportGenerator::class,
        'store_orders' => StoreOrderReportGenerator::class,
        'inventory' => InventoryReportGenerator::class,
        'suppliers' => SupplierReportGenerator::class,
        'purchase_orders' => PurchaseOrderReportGenerator::class,
        'goods_receipts' => GoodsReceiptReportGenerator::class,
        'customers' => CustomerReportGenerator::class,
    ];

    public function test_manager_resolves_dedicated_generator_for_each_report_type(): void
    {
        $manager = $this->app->make(ReportManager::class);

        foreach (ReportType::cases() as $type) {
            $generator = $manager->generatorFor($type);

            $this->assertInstanceOf(self::EXPECTED_GENERATORS[$type->value], $generator);
        }
    }

    public function test_manager_resolves_generator_from_string_report_type(): void
    {
        $manager = $this->app->make(ReportManager::class);

        $this->assertInstanceOf(PaymentReportGenerator::class, $manager->generatorFor('payments'));
    }

    public function test_manager_throws_domain_exception_for_unsupported_report_type(): void
    {
        $manager = $this->reportManagerWithoutGenerators();

        $this->expectException(UnsupportedReportTypeException::class);
        $this->expectExceptionMessage('Report type [bookings] is not supported.');

        $manager->generatorFor(ReportType::Bookings);
    }

    public function test_manager_throws_domain_exception_for_unknown_report_type_string(): void
    {
        $manager = $this->app->make(ReportManager::class);

        $this->expectException(UnsupportedReportTypeException::class);
        $this->expectExceptionMessage('Report type [revenue] is not supported.');

        $manager->generatorFor('revenue');
    }

    public function test_every_registered_generator_implements_the_interface(): void
    {
        $manager = $this->app->make(ReportManager::class);

        $this->assertCount(count(ReportType::cases()), $manager->generators());

        foreach ($manager->generators() as $generator) {
            $this->assertInstanceOf(ReportGeneratorInterface::class, $generator);
        }
    }

    public function test_every_report_type_resolves_exactly_one_generator(): void
    {
        $manager = $this->app->make(ReportManager::class);

        foreach (ReportType::cases() as $type) {
            $supporting = array_filter(
                $manager->generators(),
                fn (ReportGeneratorInterface $generator): bool => $generator->supports($type),
            );

            $this->assertCount(1, $supporting, "Report type [{$type->value}] must resolve exactly one generator.");
        }
    }

    public function test_each_generator_supports_only_its_own_report_type(): void
    {
        $manager = $this->app->make(ReportManager::class);

        foreach ($manager->generators() as $generator) {
            $supportedTypes = array_filter(
                ReportType::cases(),
                fn (ReportType $type): bool => $generator->supports($type),
            );

            $this->assertCount(1, $supportedTypes, $generator::class.' must support exactly one report type.');
        }
    }

    public function test_generators_return_v1_json_output_structure(): void
    {
        $manager = $this->app->make(ReportManager::class);
        $filters = $this->app->make(NormalizeReportFiltersAction::class)
            ->handle(['date_from' => '2026-01-01', 'date_to' => '2026-07-16']);

        foreach (ReportType::cases() as $type) {
            $payload = $manager->generatorFor($type)->generate($filters);

            $this->assertSame(
                ['report_type', 'generated_at', 'applied_filters', 'summary', 'rows', 'pagination'],
                array_keys($payload),
            );
            $this->assertSame($type->value, $payload['report_type']);
            $this->assertNotEmpty($payload['generated_at']);
            $this->assertSame($filters->toArray(), $payload['applied_filters']);
            $this->assertIsArray($payload['summary']);
            $this->assertArrayHasKey('total_records', $payload['summary']);
            $this->assertSame([], $payload['rows']);
            $this->assertJson(json_encode($payload));
        }
    }

    public function test_generator_summary_counts_source_records(): void
    {
        Supplier::factory()->count(3)->create();

        $payload = $this->app->make(ReportManager::class)
            ->generatorFor(ReportType::Suppliers)
            ->generate(new NormalizedReportFilters);

        $this->assertSame(3, $payload['summary']['total_records']);
    }

    public function test_generate_report_action_returns_payload_and_persists_metadata(): void
    {
        $admin = Admin::factory()->create();
        $filters = $this->app->make(NormalizeReportFiltersAction::class)
            ->handle(['status' => 'confirmed']);

        $result = $this->app->make(GenerateReportAction::class)
            ->handle($admin, ReportType::Bookings, $filters);

        $this->assertSame(ReportType::Bookings->value, $result['payload']['report_type']);
        $this->assertSame($filters->toArray(), $result['payload']['applied_filters']);

        $this->assertDatabaseHas('reports', [
            'id' => $result['report']->id,
            'report_type' => ReportType::Bookings->value,
            'format' => ReportFormat::Json->value,
            'generated_by' => $admin->id,
        ]);
        $this->assertSame($filters->toArray(), $result['report']->fresh()->filters);
    }

    public function test_generate_report_action_rejects_unsupported_type_without_persisting(): void
    {
        $admin = Admin::factory()->create();
        $action = new GenerateReportAction($this->reportManagerWithoutGenerators());

        try {
            $action->handle($admin, ReportType::Customers);
            $this->fail('Expected UnsupportedReportTypeException was not thrown.');
        } catch (UnsupportedReportTypeException) {
            $this->assertDatabaseCount('reports', 0);
        }
    }

    private function reportManagerWithoutGenerators(): ReportManager
    {
        return new ReportManager(
            $this->app->make(RevenueReportService::class),
            $this->app->make(BookingReportService::class),
            $this->app->make(CustomerReportService::class),
            $this->app->make(InventoryReportService::class),
        );
    }
}
