<?php

namespace Tests\Feature\Report;

use App\Contracts\Reports\Pdf\PdfReportGeneratorInterface;
use App\Contracts\Reports\ReportDataInterface;
use App\DataTransferObjects\Reports\BookingReportData;
use App\DataTransferObjects\Reports\CustomerReportData;
use App\DataTransferObjects\Reports\InventoryReportData;
use App\DataTransferObjects\Reports\RevenueReportData;
use App\Services\Reports\Pdf\PdfReportGenerator;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

class PdfReportGeneratorTest extends TestCase
{
    public function test_pdf_generator_resolves_through_the_container_as_a_singleton(): void
    {
        $generator = $this->app->make(PdfReportGeneratorInterface::class);

        $this->assertInstanceOf(PdfReportGenerator::class, $generator);
        $this->assertSame($generator, $this->app->make(PdfReportGeneratorInterface::class));
    }

    public function test_pdf_generator_accepts_report_dtos_only(): void
    {
        $parameters = (new ReflectionMethod(PdfReportGeneratorInterface::class, 'generate'))
            ->getParameters();

        $this->assertCount(1, $parameters);

        $type = $parameters[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(ReportDataInterface::class, $type->getName());
    }

    public function test_every_report_dto_implements_the_report_data_contract(): void
    {
        foreach ($this->reportDataObjects() as $class => $report) {
            $this->assertInstanceOf(
                ReportDataInterface::class,
                $report,
                "{$class} must implement ReportDataInterface.",
            );
        }
    }

    public function test_pdf_generator_has_no_repository_dependencies(): void
    {
        $parameters = (new ReflectionClass(PdfReportGenerator::class))
            ->getConstructor()
            ->getParameters();

        $this->assertCount(1, $parameters);

        $type = $parameters[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(Container::class, $type->getName());
        $this->assertStringNotContainsString('Repositories', $type->getName());
    }

    public function test_identical_dto_always_produces_identical_pdf_content(): void
    {
        $generator = $this->app->make(PdfReportGeneratorInterface::class);
        $report = $this->revenueReport();

        $first = $generator->generate($report);
        $second = $generator->generate($report);

        $this->assertStringStartsWith('%PDF-', $first);
        $this->assertSame(
            $this->normalizePdf($first),
            $this->normalizePdf($second),
            'Identical DTOs must produce identical PDF content.',
        );
    }

    public function test_pdf_generator_renders_every_supported_report_dto(): void
    {
        $generator = $this->app->make(PdfReportGeneratorInterface::class);

        foreach ($this->reportDataObjects() as $class => $report) {
            $pdf = $generator->generate($report);

            $this->assertStringStartsWith('%PDF-', $pdf, "{$class} must render to a PDF document.");
            $this->assertNotEmpty($pdf);
        }
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
     * Strip the volatile PDF metadata (creation timestamp and document ID)
     * that dompdf stamps with the wall-clock time, leaving only the actual
     * document content for comparison.
     */
    private function normalizePdf(string $pdf): string
    {
        $pdf = (string) preg_replace('/\/(CreationDate|ModDate)\s*\(D:[^)]*\)/', '', $pdf);

        return (string) preg_replace('/\/ID\s*\[\s*<[^>]*>\s*<[^>]*>\s*\]/', '', $pdf);
    }
}
