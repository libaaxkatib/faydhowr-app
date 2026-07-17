<?php

namespace Tests\Feature\Report;

use App\Enums\AdminPermission;
use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\Report;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, string>
     */
    private const ENDPOINTS = [
        '/api/v1/admin/reports/bookings' => 'bookings',
        '/api/v1/admin/reports/quotations' => 'quotations',
        '/api/v1/admin/reports/orders' => 'orders',
        '/api/v1/admin/reports/payments' => 'payments',
        '/api/v1/admin/reports/store-orders' => 'store_orders',
        '/api/v1/admin/reports/inventory' => 'inventory',
        '/api/v1/admin/reports/suppliers' => 'suppliers',
        '/api/v1/admin/reports/purchase-orders' => 'purchase_orders',
        '/api/v1/admin/reports/goods-receipts' => 'goods_receipts',
        '/api/v1/admin/reports/customers' => 'customers',
    ];

    public function test_every_report_endpoint_generates_its_mapped_report_type(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        foreach (self::ENDPOINTS as $endpoint => $expectedType) {
            $response = $this
                ->withToken($token)
                ->postJson($endpoint, []);

            $response
                ->assertCreated()
                ->assertJsonPath('success', true)
                ->assertJsonPath('message', 'Report generated successfully.')
                ->assertJsonPath('data.report.report_type', $expectedType)
                ->assertJsonPath('data.report.format', ReportFormat::Json->value)
                ->assertJsonPath('data.report.generated_by', $admin->id)
                ->assertJsonPath('data.payload.report_type', $expectedType)
                ->assertJsonPath('data.payload.rows', []);

            $this->assertIsArray($response->json('data.payload.summary'));
            $this->assertArrayHasKey('total_records', $response->json('data.payload.summary'));
        }

        $this->assertDatabaseCount('reports', count(self::ENDPOINTS));
    }

    public function test_report_metadata_is_persisted_without_rows(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        Supplier::factory()->count(2)->create();

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/admin/reports/suppliers', [
                'filters' => [
                    'date_from' => now()->subMonth()->toDateString(),
                    'date_to' => now()->addDay()->toDateString(),
                    'status' => ' Active ',
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.payload.summary.total_records', 2)
            ->assertJsonPath('data.payload.applied_filters.status', 'active');

        $report = Report::query()->sole();

        $this->assertSame(ReportType::Suppliers, $report->report_type);
        $this->assertSame($admin->id, $report->generated_by);
        $this->assertSame('active', $report->filters['status']);
        $this->assertArrayNotHasKey('rows', $report->getAttributes());
        $this->assertArrayNotHasKey('payload', $report->getAttributes());
    }

    public function test_client_sent_report_type_is_rejected(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/admin/reports/bookings', [
                'report_type' => 'customers',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_invalid_filters_are_rejected_without_persisting(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/admin/reports/bookings', [
                'filters' => [
                    'date_from' => '2026-07-16',
                    'date_to' => '2026-01-01',
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'INVALID_REPORT_FILTER');

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_unauthenticated_access_is_rejected_on_every_endpoint(): void
    {
        foreach (array_keys(self::ENDPOINTS) as $endpoint) {
            $this->postJson($endpoint, [])
                ->assertStatus(401);
        }

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_customers_cannot_access_report_endpoints(): void
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('customer')->plainTextToken;

        foreach (array_keys(self::ENDPOINTS) as $endpoint) {
            $this
                ->withToken($token)
                ->postJson($endpoint, [])
                ->assertStatus(401)
                ->assertJsonPath('error_code', 'UNAUTHENTICATED');
        }

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_admin_without_reports_view_permission_is_rejected(): void
    {
        $admin = Admin::factory()->create();

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/admin/reports/bookings', [])
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_admin_with_direct_reports_view_permission_can_generate_reports(): void
    {
        $admin = Admin::factory()->create();
        $this->grantPermissions($admin, [AdminPermission::ReportsView]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/admin/reports/orders', [])
            ->assertCreated()
            ->assertJsonPath('data.report.report_type', ReportType::Orders->value);

        $this->assertDatabaseCount('reports', 1);
    }

    /**
     * @param  list<AdminPermission>  $permissions
     */
    private function grantPermissions(Admin $admin, array $permissions): void
    {
        $now = now();

        DB::table('admin_permissions')->insert(
            collect($permissions)
                ->map(fn (AdminPermission $permission): array => [
                    'admin_id' => $admin->id,
                    'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
        );
    }
}
