<?php

namespace Tests\Feature\Report;

use App\Actions\Report\DownloadReportExportAction;
use App\Contracts\Reports\Storage\ReportStorageInterface;
use App\Enums\ReportExportFormat;
use App\Enums\ReportType;
use App\Models\Admin;
use App\Models\Report;
use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ReportExportDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('report_exports.default_disk'));

        $this->admin = Admin::factory()->superAdmin()->create();
        $this->token = $this->admin->createToken('admin-panel')->plainTextToken;
    }

    private function downloadExport(int $exportId): TestResponse
    {
        return $this
            ->withToken($this->token)
            ->get("/api/v1/admin/report-exports/{$exportId}/download");
    }

    private function completedExportWithFile(ReportExportFormat $format = ReportExportFormat::Pdf): ReportExport
    {
        $export = ReportExport::factory()->completed()->format($format)->create();

        $path = "reports/exports/{$export->id}/suppliers-placeholder.{$format->fileExtension()}";
        $export->forceFill(['file_path' => $path])->save();

        Storage::disk((string) config('report_exports.default_disk'))->put($path, '');

        return $export;
    }

    public function test_completed_export_downloads_successfully_with_filename_and_mime_type(): void
    {
        $export = $this->completedExportWithFile();

        $response = $this->downloadExport($export->id);

        $response->assertOk();
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString(
            "suppliers-{$export->id}.pdf",
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_xlsx_export_downloads_with_xlsx_filename_and_mime_type(): void
    {
        $export = $this->completedExportWithFile(ReportExportFormat::Xlsx);

        $response = $this->downloadExport($export->id);

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type'),
        );
        $this->assertStringContainsString(
            "suppliers-{$export->id}.xlsx",
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_pending_export_is_rejected_with_conflict(): void
    {
        $export = ReportExport::factory()->create();

        $this->downloadExport($export->id)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'EXPORT_NOT_READY');
    }

    public function test_processing_export_is_rejected_with_conflict(): void
    {
        $export = ReportExport::factory()->processing()->create();

        $this->downloadExport($export->id)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'EXPORT_NOT_READY');
    }

    public function test_failed_export_is_rejected_with_gone(): void
    {
        $export = ReportExport::factory()->failed()->create();

        $this->downloadExport($export->id)
            ->assertStatus(410)
            ->assertJsonPath('error_code', 'EXPORT_FAILED');
    }

    public function test_missing_export_returns_not_found(): void
    {
        $this->downloadExport(999999)->assertStatus(404);
    }

    public function test_completed_export_with_missing_file_returns_not_found(): void
    {
        $export = ReportExport::factory()->completed()->create();

        $this->downloadExport($export->id)
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'EXPORT_FILE_MISSING');
    }

    public function test_unauthenticated_download_is_rejected(): void
    {
        $export = $this->completedExportWithFile();

        $this->getJson("/api/v1/admin/report-exports/{$export->id}/download")
            ->assertStatus(401);
    }

    public function test_customers_cannot_download_exports(): void
    {
        $export = $this->completedExportWithFile();
        $customer = User::factory()->create();

        $this
            ->withToken($customer->createToken('customer')->plainTextToken)
            ->getJson("/api/v1/admin/report-exports/{$export->id}/download")
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_admin_without_reports_view_permission_cannot_download(): void
    {
        $export = $this->completedExportWithFile();
        $admin = Admin::factory()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson("/api/v1/admin/report-exports/{$export->id}/download")
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_download_streams_through_storage_abstraction(): void
    {
        $spy = new class implements ReportStorageInterface
        {
            public ?int $downloadedExportId = null;

            public function reservePath(ReportExport $export): string
            {
                return 'spy/path';
            }

            public function writePlaceholder(ReportExport $export, string $path): void {}

            public function write(ReportExport $export, string $path, string $contents): void {}

            public function exists(string $path): bool
            {
                return true;
            }

            public function delete(string $path): bool
            {
                return true;
            }

            public function download(ReportExport $export): StreamedResponse
            {
                $this->downloadedExportId = $export->id;

                return new StreamedResponse(fn () => null);
            }
        };

        $this->app->instance(ReportStorageInterface::class, $spy);

        $export = ReportExport::factory()->completed()->create();

        $this->downloadExport($export->id)->assertOk();

        $this->assertSame($export->id, $spy->downloadedExportId);
    }

    public function test_full_export_then_download_flow(): void
    {
        $report = Report::factory()->type(ReportType::Suppliers)->forAdmin($this->admin)->create();

        $exportId = $this
            ->withToken($this->token)
            ->postJson("/api/v1/admin/reports/{$report->id}/export", ['format' => 'pdf'])
            ->assertStatus(202)
            ->json('data.export_id');

        $response = $this->downloadExport($exportId);

        $response->assertOk();
        $this->assertStringContainsString(
            "suppliers-{$exportId}.pdf",
            (string) $response->headers->get('content-disposition'),
        );
    }

    public function test_download_action_has_no_direct_storage_facade_usage(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(DownloadReportExportAction::class))->getFileName(),
        );

        $this->assertStringNotContainsString('Illuminate\Support\Facades\Storage', $source);
        $this->assertStringNotContainsString('Storage::', $source);
    }
}
