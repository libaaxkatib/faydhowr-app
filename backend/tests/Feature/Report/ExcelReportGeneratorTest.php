<?php

namespace Tests\Feature\Report;

use App\Contracts\Reports\Excel\ExcelReportGeneratorInterface;
use App\Contracts\Reports\ReportDataInterface;
use App\DataTransferObjects\Reports\BookingReportData;
use App\DataTransferObjects\Reports\CustomerReportData;
use App\DataTransferObjects\Reports\InventoryReportData;
use App\DataTransferObjects\Reports\RevenueReportData;
use App\Services\Reports\Excel\ExcelReportGenerator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

class ExcelReportGeneratorTest extends TestCase
{
    public function test_excel_generator_resolves_through_the_container_as_a_singleton(): void
    {
        $generator = $this->app->make(ExcelReportGeneratorInterface::class);

        $this->assertInstanceOf(ExcelReportGenerator::class, $generator);
        $this->assertSame($generator, $this->app->make(ExcelReportGeneratorInterface::class));
    }

    public function test_excel_generator_accepts_report_dtos_only(): void
    {
        $parameters = (new ReflectionMethod(ExcelReportGeneratorInterface::class, 'generate'))
            ->getParameters();

        $this->assertCount(1, $parameters);

        $type = $parameters[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(ReportDataInterface::class, $type->getName());
    }

    public function test_excel_generator_has_no_repository_dependencies(): void
    {
        $constructor = (new ReflectionClass(ExcelReportGenerator::class))->getConstructor();

        $this->assertTrue(
            $constructor === null || $constructor->getNumberOfParameters() === 0,
            'ExcelReportGenerator must not own repository or service dependencies.',
        );
    }

    public function test_identical_dto_always_produces_identical_spreadsheet_content(): void
    {
        $generator = $this->app->make(ExcelReportGeneratorInterface::class);
        $report = $this->revenueReport();

        $first = $generator->generate($report);
        $second = $generator->generate($report);

        $this->assertStringStartsWith('PK', $first);
        $this->assertSame(
            $this->workbookCells($first),
            $this->workbookCells($second),
            'Identical DTOs must produce identical spreadsheet content.',
        );
    }

    public function test_excel_generator_renders_every_supported_report_dto(): void
    {
        $generator = $this->app->make(ExcelReportGeneratorInterface::class);

        $expectedNames = [
            RevenueReportData::class => 'Revenue Report',
            BookingReportData::class => 'Booking Report',
            CustomerReportData::class => 'Customer Report',
            InventoryReportData::class => 'Inventory Report',
        ];

        foreach ($this->reportDataObjects() as $class => $report) {
            $xlsx = $generator->generate($report);

            $this->assertStringStartsWith('PK', $xlsx, "{$class} must render to an XLSX workbook.");

            $cells = $this->workbookCells($xlsx);

            $this->assertSame((string) config('app.name'), $cells[0][0], "{$class}: company name must lead the worksheet.");
            $this->assertSame($expectedNames[$class], $cells[1][0], "{$class}: report name must follow the company name.");
            $this->assertSame('Filter Information', $cells[4][0]);
            $this->assertSame('Report Metrics', $cells[9][0]);
            $this->assertSame(['Metric', 'Value'], array_slice($cells[10], 0, 2));
            $this->assertGreaterThan(11, count($cells), "{$class}: metric rows must be present.");
        }
    }

    public function test_excel_generator_produces_a_single_worksheet(): void
    {
        $generator = $this->app->make(ExcelReportGeneratorInterface::class);

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $generator->generate($this->revenueReport()));

        $workbook = IOFactory::load($path);

        $this->assertSame(1, $workbook->getSheetCount());
        $this->assertSame('Report', $workbook->getActiveSheet()->getTitle());

        unlink($path);
    }

    /**
     * One populated DTO per supported report type.
     *
     * @return array<class-string, ReportDataInterface>
     */
    private function reportDataObjects(): array
    {
        return [
            RevenueReportData::class => $this->revenueReport(),
            BookingReportData::class => new BookingReportData(
                totalBookings: 6,
                completedBookings: 2,
                cancelledBookings: 2,
                pendingBookings: 2,
                filter: 'last_7_days',
                startDate: '2026-05-14T00:00:00+00:00',
                endDate: '2026-05-20T23:59:59+00:00',
                generatedAt: '2026-05-20T12:00:00+00:00',
            ),
            CustomerReportData::class => new CustomerReportData(
                totalCustomers: 6,
                activeCustomers: 3,
                inactiveCustomers: 3,
                newCustomers: 4,
                filter: 'all_time',
                startDate: null,
                endDate: null,
                generatedAt: '2026-05-20T12:00:00+00:00',
            ),
            InventoryReportData::class => new InventoryReportData(
                totalProducts: 6,
                inStockProducts: 2,
                lowStockProducts: 2,
                outOfStockProducts: 2,
                filter: 'this_month',
                startDate: '2026-05-01T00:00:00+00:00',
                endDate: '2026-05-31T23:59:59+00:00',
                generatedAt: '2026-05-20T12:00:00+00:00',
            ),
        ];
    }

    private function revenueReport(): RevenueReportData
    {
        return new RevenueReportData(
            totalRevenue: 630.0,
            totalPayments: 6,
            filter: 'last_30_days',
            startDate: '2026-04-21T00:00:00+00:00',
            endDate: '2026-05-20T23:59:59+00:00',
            generatedAt: '2026-05-20T12:00:00+00:00',
        );
    }

    /**
     * Parse the workbook and return the worksheet cell matrix, normalizing
     * away volatile ZIP container metadata such as entry timestamps.
     *
     * @return list<list<mixed>>
     */
    private function workbookCells(string $xlsx): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $xlsx);

        $cells = IOFactory::load($path)->getActiveSheet()->toArray();

        unlink($path);

        return $cells;
    }
}
