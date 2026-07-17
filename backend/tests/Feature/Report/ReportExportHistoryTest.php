<?php

namespace Tests\Feature\Report;

use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Enums\ReportType;
use App\Models\Admin;
use App\Models\ReportExport;
use App\Models\User;
use App\Repositories\Reports\ReportExportRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReportExportHistoryTest extends TestCase
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
     * @param  array<string, mixed>  $query
     */
    private function listExports(array $query = []): TestResponse
    {
        $queryString = http_build_query($query);

        return $this
            ->withToken($this->token)
            ->getJson('/api/v1/admin/report-exports'.($queryString === '' ? '' : "?{$queryString}"));
    }

    public function test_history_lists_exports_with_resource_serialization(): void
    {
        $export = ReportExport::factory()->completed()->create();

        $response = $this->listExports()->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.count', 1);

        $row = $response->json('data.data.0');

        $this->assertSame(
            [
                'id',
                'report_id',
                'report_type',
                'format',
                'status',
                'requested_by',
                'file_path',
                'created_at',
                'started_at',
                'completed_at',
                'failed_at',
            ],
            array_keys($row),
        );

        $this->assertSame($export->id, $row['id']);
        $this->assertSame($export->report_id, $row['report_id']);
        $this->assertSame(ReportType::Suppliers->value, $row['report_type']);
        $this->assertSame(ReportExportFormat::Pdf->value, $row['format']);
        $this->assertSame(ReportExportStatus::Completed->value, $row['status']);
        $this->assertSame($export->requested_by, $row['requested_by']);
        $this->assertSame($export->file_path, $row['file_path']);
        $this->assertNotNull($row['completed_at']);
        $this->assertNull($row['failed_at']);
    }

    public function test_pagination_metadata_uses_existing_format(): void
    {
        ReportExport::factory()->count(3)->create();

        $pagination = $this->listExports(['limit' => 2])->assertOk()->json('data.pagination');

        $this->assertSame(
            ['has_more', 'next_cursor', 'previous_cursor', 'per_page', 'count'],
            array_keys($pagination),
        );
        $this->assertTrue($pagination['has_more']);
        $this->assertSame(2, $pagination['per_page']);
        $this->assertSame(2, $pagination['count']);
    }

    public function test_cursor_pagination_traverses_history(): void
    {
        ReportExport::factory()->count(5)->create();

        $collected = [];
        $cursor = null;
        $pages = 0;

        do {
            $this->assertLessThan(10, $pages, 'Cursor traversal did not terminate.');

            $response = $this->listExports(array_filter([
                'limit' => 2,
                'cursor' => $cursor,
            ], fn (mixed $value): bool => $value !== null))->assertOk();

            $collected = array_merge($collected, array_column($response->json('data.data'), 'id'));
            $cursor = $response->json('data.pagination.next_cursor');
            $pages++;
        } while ($response->json('data.pagination.has_more'));

        $this->assertSame(3, $pages);
        $this->assertCount(5, $collected);
        $this->assertSame(array_unique($collected), $collected);
    }

    public function test_status_filter_returns_matching_exports_only(): void
    {
        ReportExport::factory()->create();
        $failed = ReportExport::factory()->failed()->create();
        ReportExport::factory()->completed()->create();

        $rows = $this->listExports(['status' => 'failed'])->assertOk()->json('data.data');

        $this->assertSame([$failed->id], array_column($rows, 'id'));
    }

    public function test_report_type_filter_returns_matching_exports_only(): void
    {
        ReportExport::factory()->create();
        $bookingExport = ReportExport::factory()->type(ReportType::Bookings)->create();

        $rows = $this->listExports(['report_type' => 'bookings'])->assertOk()->json('data.data');

        $this->assertSame([$bookingExport->id], array_column($rows, 'id'));
    }

    public function test_format_filter_returns_matching_exports_only(): void
    {
        ReportExport::factory()->create();
        $xlsxExport = ReportExport::factory()->format(ReportExportFormat::Xlsx)->create();

        $rows = $this->listExports(['format' => 'xlsx'])->assertOk()->json('data.data');

        $this->assertSame([$xlsxExport->id], array_column($rows, 'id'));
    }

    public function test_requested_by_filter_returns_matching_exports_only(): void
    {
        ReportExport::factory()->create();
        $mine = ReportExport::factory()->create(['requested_by' => $this->admin->id]);

        $rows = $this->listExports(['requested_by' => $this->admin->id])->assertOk()->json('data.data');

        $this->assertSame([$mine->id], array_column($rows, 'id'));
    }

    public function test_date_range_filter_returns_exports_in_window(): void
    {
        $old = ReportExport::factory()->create();
        $middle = ReportExport::factory()->create();
        $recent = ReportExport::factory()->create();

        ReportExport::query()->whereKey($old->id)->update(['created_at' => now()->subYear()]);
        ReportExport::query()->whereKey($middle->id)->update(['created_at' => now()->subMonth()]);

        $rows = $this->listExports([
            'created_from' => now()->subMonths(2)->toDateString(),
            'created_to' => now()->subWeek()->toDateString(),
        ])->assertOk()->json('data.data');

        $this->assertSame([$middle->id], array_column($rows, 'id'));
    }

    public function test_sorting_defaults_to_created_at_desc_and_supports_asc(): void
    {
        $first = ReportExport::factory()->create();
        $second = ReportExport::factory()->create();
        $third = ReportExport::factory()->create();

        ReportExport::query()->whereKey($first->id)->update(['created_at' => now()->subHours(2)]);
        ReportExport::query()->whereKey($second->id)->update(['created_at' => now()->subHour()]);

        $descIds = array_column($this->listExports()->assertOk()->json('data.data'), 'id');
        $this->assertSame([$third->id, $second->id, $first->id], $descIds);

        $ascIds = array_column(
            $this->listExports(['sort' => 'created_at', 'direction' => 'asc'])->assertOk()->json('data.data'),
            'id',
        );
        $this->assertSame([$first->id, $second->id, $third->id], $ascIds);
    }

    public function test_unsupported_sort_fields_and_invalid_input_are_rejected(): void
    {
        $invalidQueries = [
            ['sort' => 'id'],
            ['sort' => 'file_path'],
            ['direction' => 'up'],
            ['status' => 'bogus'],
            ['report_type' => 'unknown'],
            ['format' => 'csv'],
            ['requested_by' => 0],
            ['created_from' => 'not-a-date'],
            ['limit' => 0],
            ['limit' => 501],
        ];

        foreach ($invalidQueries as $query) {
            $this->listExports($query)
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'VALIDATION_ERROR');
        }
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/report-exports')->assertStatus(401);
    }

    public function test_customers_cannot_list_export_history(): void
    {
        $customer = User::factory()->create();

        $this
            ->withToken($customer->createToken('customer')->plainTextToken)
            ->getJson('/api/v1/admin/report-exports')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_admin_without_reports_view_permission_is_rejected(): void
    {
        $admin = Admin::factory()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/report-exports')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_repository_applies_filters_before_pagination(): void
    {
        ReportExport::factory()->count(2)->create();
        $completed = ReportExport::factory()->completed()->count(3)->create();

        $paginator = $this->app->make(ReportExportRepository::class)->paginateHistory(
            ['status' => ReportExportStatus::Completed->value],
            new ReportCursorPagination(limit: 2),
        );

        $this->assertCount(2, $paginator->items());
        $this->assertTrue($paginator->hasMorePages());

        foreach ($paginator->items() as $export) {
            $this->assertSame(ReportExportStatus::Completed, $export->status);
            $this->assertContains($export->id, $completed->pluck('id')->all());
        }
    }
}
