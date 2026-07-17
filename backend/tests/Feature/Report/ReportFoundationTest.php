<?php

namespace Tests\Feature\Report;

use App\Contracts\Reports\Generators\ReportGeneratorInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Exceptions\Reports\UnsupportedReportTypeException;
use App\Models\Admin;
use App\Models\Report;
use App\Services\Reports\ReportManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ValueError;

class ReportFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('reports'));
        $this->assertTrue(Schema::hasColumns('reports', [
            'id',
            'report_type',
            'format',
            'filters',
            'generated_by',
            'generated_at',
            'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('reports', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('reports', 'payload'));
        $this->assertFalse(Schema::hasColumn('reports', 'data'));
    }

    public function test_report_belongs_to_generating_admin(): void
    {
        $admin = Admin::factory()->create();

        $report = Report::factory()->forAdmin($admin)->create([
            'report_type' => ReportType::Payments,
            'format' => ReportFormat::Json,
        ]);

        $report->load('generatedBy');

        $this->assertInstanceOf(Admin::class, $report->generatedBy);
        $this->assertTrue($report->generatedBy->is($admin));
        $this->assertSame($admin->id, $report->generated_by);
    }

    public function test_report_type_and_format_enums_expose_approved_cases(): void
    {
        $this->assertSame([
            'bookings',
            'quotations',
            'orders',
            'payments',
            'store_orders',
            'inventory',
            'suppliers',
            'purchase_orders',
            'goods_receipts',
            'customers',
        ], ReportType::values());

        $this->assertSame(['json', 'pdf', 'excel'], ReportFormat::values());
        $this->assertTrue(ReportFormat::Json->isGeneratedInV1());
        $this->assertFalse(ReportFormat::Pdf->isGeneratedInV1());
        $this->assertFalse(ReportFormat::Excel->isGeneratedInV1());
    }

    public function test_enums_reject_invalid_values(): void
    {
        $this->expectException(ValueError::class);

        ReportType::from('revenue');
    }

    public function test_report_persists_json_filters_without_report_data(): void
    {
        $admin = Admin::factory()->create();
        $filters = [
            'status' => 'confirmed',
            'date_from' => '2026-01-01',
            'date_to' => '2026-07-16',
        ];

        $report = Report::query()->create([
            'report_type' => ReportType::Bookings,
            'format' => ReportFormat::Json,
            'filters' => $filters,
            'generated_by' => $admin->id,
            'generated_at' => now(),
        ]);

        $fresh = $report->fresh();

        $this->assertSame(ReportType::Bookings, $fresh->report_type);
        $this->assertSame(ReportFormat::Json, $fresh->format);
        $this->assertSame($filters, $fresh->filters);
        $this->assertNotNull($fresh->generated_at);
        $this->assertNotNull($fresh->created_at);
        $this->assertArrayNotHasKey('rows', $fresh->getAttributes());
        $this->assertArrayNotHasKey('result', $fresh->getAttributes());
    }

    public function test_report_factory_creates_metadata_records(): void
    {
        $report = Report::factory()->create([
            'report_type' => ReportType::Inventory,
            'format' => ReportFormat::Pdf,
            'filters' => ['warehouse' => 'main'],
        ]);

        $this->assertDatabaseHas('reports', [
            'id' => $report->id,
            'report_type' => ReportType::Inventory->value,
            'format' => ReportFormat::Pdf->value,
            'generated_by' => $report->generated_by,
        ]);

        $this->assertSame(['warehouse' => 'main'], $report->fresh()->filters);
    }

    public function test_pdf_and_excel_formats_are_metadata_only_in_v1(): void
    {
        $admin = Admin::factory()->create();

        foreach ([ReportFormat::Pdf, ReportFormat::Excel] as $format) {
            $report = Report::factory()->forAdmin($admin)->format($format)->create();

            $this->assertSame($format, $report->format);
            $this->assertFalse($format->isGeneratedInV1());
        }
    }

    public function test_report_manager_registers_and_resolves_generators(): void
    {
        $manager = new ReportManager;

        $this->assertFalse($manager->supports(ReportType::Bookings));

        $generator = new class implements ReportGeneratorInterface
        {
            public function supports(ReportType $type): bool
            {
                return $type === ReportType::Bookings;
            }

            public function generate(NormalizedReportFilters $filters, ?ReportCursorPagination $pagination = null): array
            {
                return [
                    'report_type' => ReportType::Bookings->value,
                    'generated_at' => now()->toISOString(),
                    'applied_filters' => $filters->toArray(),
                    'summary' => [],
                    'rows' => [],
                    'pagination' => [
                        'has_more' => false,
                        'next_cursor' => null,
                        'previous_cursor' => null,
                        'per_page' => ($pagination ?? new ReportCursorPagination)->limit(),
                        'count' => 0,
                    ],
                ];
            }
        };

        $manager->register($generator);

        $this->assertTrue($manager->supports(ReportType::Bookings));
        $this->assertSame(['bookings'], $manager->registeredTypes());
        $this->assertSame($generator, $manager->generatorFor(ReportType::Bookings));
        $this->assertSame(
            ['status' => 'open'],
            $manager->generatorFor(ReportType::Bookings)
                ->generate(new NormalizedReportFilters(status: 'open'))['applied_filters'],
        );
    }

    public function test_report_manager_rejects_unregistered_generators(): void
    {
        $manager = new ReportManager;

        $this->expectException(UnsupportedReportTypeException::class);
        $this->expectExceptionMessage('Report type [customers] is not supported.');

        $manager->generatorFor(ReportType::Customers);
    }

    public function test_all_report_types_can_be_persisted_as_metadata(): void
    {
        $admin = Admin::factory()->create();

        foreach (ReportType::cases() as $type) {
            $report = Report::factory()->forAdmin($admin)->type($type)->create();

            $this->assertSame($type, $report->fresh()->report_type);
        }

        $this->assertDatabaseCount('reports', count(ReportType::cases()));
    }
}
