<?php

namespace Tests\Feature\Report;

use App\Actions\Report\NormalizeReportFiltersAction;
use App\Contracts\Reports\Generators\ReportGeneratorInterface;
use App\Contracts\Reports\Repositories\ReportRepositoryInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportType;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Repositories\Reports\BookingReportRepository;
use App\Repositories\Reports\CustomerReportRepository;
use App\Repositories\Reports\GoodsReceiptReportRepository;
use App\Repositories\Reports\InventoryReportRepository;
use App\Repositories\Reports\OrderReportRepository;
use App\Repositories\Reports\PaymentReportRepository;
use App\Repositories\Reports\PurchaseOrderReportRepository;
use App\Repositories\Reports\QuotationReportRepository;
use App\Repositories\Reports\StoreOrderReportRepository;
use App\Repositories\Reports\SupplierReportRepository;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class ReportRepositoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, class-string<ReportRepositoryInterface>>
     */
    private const EXPECTED_REPOSITORIES = [
        'bookings' => BookingReportRepository::class,
        'quotations' => QuotationReportRepository::class,
        'orders' => OrderReportRepository::class,
        'payments' => PaymentReportRepository::class,
        'store_orders' => StoreOrderReportRepository::class,
        'inventory' => InventoryReportRepository::class,
        'suppliers' => SupplierReportRepository::class,
        'purchase_orders' => PurchaseOrderReportRepository::class,
        'goods_receipts' => GoodsReceiptReportRepository::class,
        'customers' => CustomerReportRepository::class,
    ];

    /**
     * @var array<class-string<ReportGeneratorInterface>, class-string<ReportRepositoryInterface>>
     */
    private const GENERATOR_REPOSITORIES = [
        BookingReportGenerator::class => BookingReportRepository::class,
        QuotationReportGenerator::class => QuotationReportRepository::class,
        OrderReportGenerator::class => OrderReportRepository::class,
        PaymentReportGenerator::class => PaymentReportRepository::class,
        StoreOrderReportGenerator::class => StoreOrderReportRepository::class,
        InventoryReportGenerator::class => InventoryReportRepository::class,
        SupplierReportGenerator::class => SupplierReportRepository::class,
        PurchaseOrderReportGenerator::class => PurchaseOrderReportRepository::class,
        GoodsReceiptReportGenerator::class => GoodsReceiptReportRepository::class,
        CustomerReportGenerator::class => CustomerReportRepository::class,
    ];

    public function test_repositories_resolve_through_the_container_and_implement_the_interface(): void
    {
        foreach (self::EXPECTED_REPOSITORIES as $repositoryClass) {
            $repository = $this->app->make($repositoryClass);

            $this->assertInstanceOf(ReportRepositoryInterface::class, $repository);
        }
    }

    public function test_every_report_type_is_supported_by_exactly_one_repository(): void
    {
        $repositories = array_map(
            fn (string $repositoryClass): ReportRepositoryInterface => $this->app->make($repositoryClass),
            array_values(self::EXPECTED_REPOSITORIES),
        );

        foreach (ReportType::cases() as $type) {
            $supporting = array_filter(
                $repositories,
                fn (ReportRepositoryInterface $repository): bool => $repository->supports($type),
            );

            $this->assertCount(1, $supporting, "Report type [{$type->value}] must be supported by exactly one repository.");
            $this->assertInstanceOf(self::EXPECTED_REPOSITORIES[$type->value], array_values($supporting)[0]);
        }
    }

    public function test_every_generator_depends_on_its_dedicated_repository(): void
    {
        foreach (self::GENERATOR_REPOSITORIES as $generatorClass => $repositoryClass) {
            $constructor = (new ReflectionClass($generatorClass))->getConstructor();

            $this->assertNotNull($constructor, $generatorClass.' must inject its repository.');

            $parameterType = $constructor->getParameters()[0]->getType();

            $this->assertInstanceOf(ReflectionNamedType::class, $parameterType);
            $this->assertSame(
                $repositoryClass,
                $parameterType->getName(),
                $generatorClass.' must depend on '.$repositoryClass,
            );
        }
    }

    public function test_generators_contain_no_direct_model_queries(): void
    {
        foreach (array_keys(self::GENERATOR_REPOSITORIES) as $generatorClass) {
            $source = (string) file_get_contents((new ReflectionClass($generatorClass))->getFileName());

            $this->assertStringNotContainsString(
                'App\Models',
                $source,
                $generatorClass.' must not reference Eloquent models.',
            );
            $this->assertStringNotContainsString(
                '::query(',
                $source,
                $generatorClass.' must not build queries.',
            );
        }
    }

    public function test_repository_computes_summary(): void
    {
        Supplier::factory()->count(3)->create();
        Supplier::factory()->inactive()->create();

        $summary = $this->app->make(SupplierReportRepository::class)
            ->summary($this->normalize([]));

        $this->assertSame(['total_records' => 4], $summary);
    }

    public function test_repository_computes_monetary_summary(): void
    {
        $supplier = Supplier::factory()->create();
        PurchaseOrder::factory()->for($supplier)->create(['subtotal' => 150.25]);
        PurchaseOrder::factory()->for($supplier)->create(['subtotal' => 49.75]);

        $summary = $this->app->make(PurchaseOrderReportRepository::class)
            ->summary($this->normalize([]));

        $this->assertSame(2, $summary['total_records']);
        $this->assertSame(200.0, $summary['total_amount']);
    }

    public function test_repository_returns_dataset_rows(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'Acme Traders']);

        $rows = $this->app->make(SupplierReportRepository::class)
            ->rows($this->normalize([]), new ReportCursorPagination)
            ->items();

        $this->assertCount(1, $rows);
        $this->assertSame($supplier->id, $rows[0]['id']);
        $this->assertSame('Acme Traders', $rows[0]['name']);
        $this->assertSame(
            ['id', 'name', 'contact_person', 'email', 'status', 'created_at'],
            array_keys($rows[0]),
        );
    }

    public function test_repository_applies_status_filter(): void
    {
        Supplier::factory()->count(2)->create();
        Supplier::factory()->inactive()->create();

        $repository = $this->app->make(SupplierReportRepository::class);
        $filters = $this->normalize(['status' => ' Active ']);

        $this->assertSame(2, $repository->summary($filters)['total_records']);
        $this->assertCount(2, $repository->rows($filters, new ReportCursorPagination)->items());
    }

    public function test_repository_applies_supplier_id_filter(): void
    {
        $supplier = Supplier::factory()->create();
        $other = Supplier::factory()->create();
        PurchaseOrder::factory()->for($supplier)->count(2)->create();
        PurchaseOrder::factory()->for($other)->create();

        $repository = $this->app->make(PurchaseOrderReportRepository::class);
        $filters = $this->normalize(['supplier_id' => (string) $supplier->id]);

        $this->assertSame(2, $repository->summary($filters)['total_records']);

        foreach ($repository->rows($filters, new ReportCursorPagination)->items() as $row) {
            $this->assertSame($supplier->id, $row['supplier_id']);
        }
    }

    public function test_repository_applies_date_range_filter(): void
    {
        $recent = Supplier::factory()->create();
        $old = Supplier::factory()->create();
        Supplier::query()->whereKey($old->id)->update(['created_at' => now()->subYear()]);

        $repository = $this->app->make(SupplierReportRepository::class);
        $filters = $this->normalize(['date_from' => now()->subMonth()->toDateString()]);

        $rows = $repository->rows($filters, new ReportCursorPagination)->items();

        $this->assertSame(1, $repository->summary($filters)['total_records']);
        $this->assertCount(1, $rows);
        $this->assertSame($recent->id, $rows[0]['id']);
    }

    public function test_generator_payload_matches_repository_data(): void
    {
        Supplier::factory()->count(2)->create();

        $filters = $this->normalize([]);
        $payload = $this->app->make(ReportManager::class)
            ->generatorFor(ReportType::Suppliers)
            ->generate($filters);

        $repository = $this->app->make(SupplierReportRepository::class);

        $this->assertSame($repository->summary($filters), $payload['summary']);
        $this->assertSame($repository->rows($filters, new ReportCursorPagination)->items(), $payload['rows']);
        $this->assertCount(2, $payload['rows']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function normalize(array $filters): NormalizedReportFilters
    {
        return $this->app->make(NormalizeReportFiltersAction::class)->handle($filters);
    }
}
