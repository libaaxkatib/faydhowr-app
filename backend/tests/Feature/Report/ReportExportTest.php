<?php

namespace Tests\Feature\Report;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Enums\ReportType;
use App\Events\Reports\ReportExportCompleted;
use App\Events\Reports\ReportExportFailed;
use App\Events\Reports\ReportExportRequested;
use App\Jobs\Reports\GenerateReportExportJob;
use App\Models\Admin;
use App\Models\Report;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Reports\ReportExportManager;
use App\Services\Reports\ReportManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->superAdmin()->create();
        $this->token = $this->admin->createToken('admin-panel')->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function requestExport(Report $report, array $body = []): TestResponse
    {
        return $this
            ->withToken($this->token)
            ->postJson("/api/v1/admin/reports/{$report->id}/export", $body);
    }

    public function test_export_request_is_accepted_with_export_id_and_status(): void
    {
        Storage::fake();
        $report = Report::factory()->type(ReportType::Suppliers)->forAdmin($this->admin)->create();

        $response = $this->requestExport($report, ['format' => 'pdf']);

        $response
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Report export queued successfully.')
            ->assertJsonPath('data.status', ReportExportStatus::Pending->value);

        $this->assertIsInt($response->json('data.export_id'));
    }

    public function test_export_record_is_created_with_normalized_filters(): void
    {
        Queue::fake();
        $report = Report::factory()->type(ReportType::Suppliers)->forAdmin($this->admin)->create();

        $this->requestExport($report, [
            'format' => 'xlsx',
            'filters' => ['status' => ' Active '],
        ])->assertStatus(202);

        $export = ReportExport::query()->sole();

        $this->assertSame($report->id, $export->report_id);
        $this->assertSame(ReportType::Suppliers, $export->report_type);
        $this->assertSame($this->admin->id, $export->requested_by);
        $this->assertSame(ReportExportFormat::Xlsx, $export->format);
        $this->assertSame(['status' => 'active'], $export->filters);
        $this->assertSame(ReportExportStatus::Pending, $export->status);
        $this->assertNull($export->file_path);
        $this->assertNull($export->started_at);
    }

    public function test_export_request_dispatches_requested_event(): void
    {
        Event::fake([ReportExportRequested::class]);
        $report = Report::factory()->type(ReportType::Suppliers)->forAdmin($this->admin)->create();

        $this->requestExport($report, ['format' => 'pdf'])->assertStatus(202);

        Event::assertDispatched(
            ReportExportRequested::class,
            fn (ReportExportRequested $event): bool => $event->reportExport->report_id === $report->id,
        );
    }

    public function test_requested_event_queues_generate_report_export_job(): void
    {
        Queue::fake();
        $report = Report::factory()->type(ReportType::Suppliers)->forAdmin($this->admin)->create();

        $this->requestExport($report, ['format' => 'pdf'])->assertStatus(202);

        $export = ReportExport::query()->sole();

        Queue::assertPushed(
            GenerateReportExportJob::class,
            fn (GenerateReportExportJob $job): bool => $job->reportExportId === $export->id,
        );
    }

    public function test_processing_transitions_status_from_pending_to_completed(): void
    {
        Storage::fake();
        Event::fake([ReportExportCompleted::class]);

        $export = ReportExport::factory()->create();

        $this->assertSame(ReportExportStatus::Pending, $export->status);

        $processed = $this->app->make(ReportExportManager::class)->process($export->id);

        $this->assertSame(ReportExportStatus::Completed, $processed->status);
        $this->assertNotNull($processed->started_at);
        $this->assertNotNull($processed->completed_at);
        $this->assertNull($processed->failed_at);
        $this->assertNull($processed->failure_reason);

        Event::assertDispatched(
            ReportExportCompleted::class,
            fn (ReportExportCompleted $event): bool => $event->reportExport->id === $export->id,
        );
    }

    public function test_terminal_exports_are_not_reprocessed(): void
    {
        Event::fake([ReportExportCompleted::class, ReportExportFailed::class]);

        $export = ReportExport::factory()->failed()->create();

        $processed = $this->app->make(ReportExportManager::class)->process($export->id);

        $this->assertSame(ReportExportStatus::Failed, $processed->status);
        Event::assertNothingDispatched();
    }

    public function test_failed_export_records_reason_and_dispatches_failed_event(): void
    {
        Event::fake([ReportExportFailed::class]);
        $this->app->instance(ReportManager::class, new ReportManager);

        $export = ReportExport::factory()->create();

        $processed = $this->app->make(ReportExportManager::class)->process($export->id);

        $this->assertSame(ReportExportStatus::Failed, $processed->status);
        $this->assertNotNull($processed->started_at);
        $this->assertNotNull($processed->failed_at);
        $this->assertNotNull($processed->failure_reason);
        $this->assertNull($processed->completed_at);
        $this->assertNull($processed->file_path);

        Event::assertDispatched(
            ReportExportFailed::class,
            fn (ReportExportFailed $event): bool => $event->reportExport->id === $export->id,
        );
    }

    public function test_completed_export_stores_placeholder_file_path(): void
    {
        Storage::fake();

        $export = ReportExport::factory()->format(ReportExportFormat::Xlsx)->create();

        $processed = $this->app->make(ReportExportManager::class)->process($export->id);

        $this->assertNotNull($processed->file_path);
        $this->assertStringStartsWith("reports/exports/{$export->id}/suppliers-", $processed->file_path);
        $this->assertStringEndsWith('.xlsx', $processed->file_path);

        Storage::assertExists($processed->file_path);
    }

    public function test_full_export_flow_completes_on_sync_queue(): void
    {
        Storage::fake();
        $report = Report::factory()->type(ReportType::Suppliers)->forAdmin($this->admin)->create();

        $this->requestExport($report, ['format' => 'pdf'])->assertStatus(202);

        $export = ReportExport::query()->sole();

        $this->assertSame(ReportExportStatus::Completed, $export->status);
        $this->assertNotNull($export->file_path);
        Storage::assertExists($export->file_path);
    }

    public function test_unauthenticated_export_request_is_rejected(): void
    {
        $report = Report::factory()->create();

        $this->postJson("/api/v1/admin/reports/{$report->id}/export", ['format' => 'pdf'])
            ->assertStatus(401);

        $this->assertDatabaseCount('report_exports', 0);
    }

    public function test_customers_cannot_request_exports(): void
    {
        $report = Report::factory()->create();
        $customer = User::factory()->create();

        $this
            ->withToken($customer->createToken('customer')->plainTextToken)
            ->postJson("/api/v1/admin/reports/{$report->id}/export", ['format' => 'pdf'])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->assertDatabaseCount('report_exports', 0);
    }

    public function test_admin_without_reports_view_permission_cannot_request_exports(): void
    {
        $report = Report::factory()->create();
        $admin = Admin::factory()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson("/api/v1/admin/reports/{$report->id}/export", ['format' => 'pdf'])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->assertDatabaseCount('report_exports', 0);
    }

    public function test_missing_format_is_rejected(): void
    {
        $report = Report::factory()->create();

        $this->requestExport($report, [])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('report_exports', 0);
    }

    public function test_unsupported_formats_are_rejected(): void
    {
        $report = Report::factory()->create();

        foreach (['json', 'excel', 'csv'] as $format) {
            $this->requestExport($report, ['format' => $format])
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'VALIDATION_ERROR');
        }

        $this->assertDatabaseCount('report_exports', 0);
    }

    public function test_client_sent_report_type_is_rejected(): void
    {
        $report = Report::factory()->create();

        $this->requestExport($report, ['format' => 'pdf', 'report_type' => 'customers'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('report_exports', 0);
    }

    public function test_invalid_filters_are_rejected_without_creating_export(): void
    {
        $report = Report::factory()->create();

        $this->requestExport($report, [
            'format' => 'pdf',
            'filters' => [
                'date_from' => '2026-07-16',
                'date_to' => '2026-01-01',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_REPORT_FILTER');

        $this->assertDatabaseCount('report_exports', 0);
    }

    public function test_export_for_missing_report_returns_not_found(): void
    {
        $this
            ->withToken($this->token)
            ->postJson('/api/v1/admin/reports/999999/export', ['format' => 'pdf'])
            ->assertStatus(404);

        $this->assertDatabaseCount('report_exports', 0);
    }
}
