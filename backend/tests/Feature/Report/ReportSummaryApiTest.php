<?php

namespace Tests\Feature\Report;

use App\Actions\Report\GetReportSummaryAction;
use App\Contracts\Reports\ReportManagerInterface;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationStatus;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Quotation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class ReportSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    private const XLSX_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private Admin $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->superAdmin()->create();
        $this->token = $this->admin->createToken('admin-panel')->plainTextToken;
    }

    private function getReport(string $path, array $query = []): TestResponse
    {
        return $this
            ->withToken($this->token)
            ->getJson('/api/v1/admin/reports/'.$path.($query === [] ? '' : '?'.http_build_query($query)));
    }

    public function test_revenue_report_endpoint_returns_the_service_dto_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00:00'));
        $this->seedPayments();

        $expected = $this->app->make(ReportManagerInterface::class)
            ->revenueReports()
            ->generate()
            ->toArray();

        $this->getReport('revenue')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', $expected);
    }

    public function test_every_summary_endpoint_returns_its_dto_contract(): void
    {
        $expectedKeys = [
            'revenue' => ['total_revenue', 'total_payments'],
            'bookings' => ['total_bookings', 'completed_bookings', 'cancelled_bookings', 'pending_bookings'],
            'customers' => ['total_customers', 'active_customers', 'inactive_customers', 'new_customers'],
            'inventory' => ['total_products', 'in_stock_products', 'low_stock_products', 'out_of_stock_products'],
        ];

        foreach ($expectedKeys as $path => $metricKeys) {
            $this->getReport($path)
                ->assertOk()
                ->assertJsonStructure([
                    'data' => [...$metricKeys, 'filter', 'start_date', 'end_date', 'generated_at'],
                ])
                ->assertJsonPath('data.filter', 'all_time');
        }
    }

    public function test_summary_endpoints_apply_the_date_filter(): void
    {
        $this->getReport('revenue', ['filter' => 'today'])
            ->assertOk()
            ->assertJsonPath('data.filter', 'today');

        $this->getReport('bookings', [
            'filter' => 'custom_date_range',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
        ])
            ->assertOk()
            ->assertJsonPath('data.filter', 'custom_date_range');
    }

    public function test_summary_endpoints_reject_invalid_filters(): void
    {
        $this->getReport('revenue', ['filter' => 'invalid'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->getReport('customers', ['filter' => 'custom_date_range'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_pdf_endpoints_return_generated_pdf_documents(): void
    {
        $expectedFilenames = [
            'revenue' => 'revenue-report.pdf',
            'bookings' => 'booking-report.pdf',
            'customers' => 'customer-report.pdf',
            'inventory' => 'inventory-report.pdf',
        ];

        foreach ($expectedFilenames as $path => $filename) {
            $response = $this->getReport($path.'/pdf')->assertOk();

            $this->assertSame('application/pdf', $response->headers->get('content-type'));
            $this->assertStringContainsString($filename, (string) $response->headers->get('content-disposition'));
            $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        }
    }

    public function test_excel_endpoints_return_generated_xlsx_workbooks(): void
    {
        $expectedFilenames = [
            'revenue' => 'revenue-report.xlsx',
            'bookings' => 'booking-report.xlsx',
            'customers' => 'customer-report.xlsx',
            'inventory' => 'inventory-report.xlsx',
        ];

        foreach ($expectedFilenames as $path => $filename) {
            $response = $this->getReport($path.'/excel')->assertOk();

            $this->assertSame(self::XLSX_CONTENT_TYPE, $response->headers->get('content-type'));
            $this->assertStringContainsString($filename, (string) $response->headers->get('content-disposition'));
            $this->assertStringStartsWith('PK', (string) $response->getContent());
        }
    }

    public function test_summary_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/reports/revenue')->assertStatus(401);
        $this->getJson('/api/v1/admin/reports/inventory/pdf')->assertStatus(401);
    }

    public function test_summary_endpoints_require_the_reports_view_permission(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('admin-panel')->plainTextToken;

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/reports/revenue')
            ->assertStatus(403);
    }

    public function test_summary_action_depends_only_on_the_report_manager_interface(): void
    {
        $parameters = (new ReflectionClass(GetReportSummaryAction::class))
            ->getConstructor()
            ->getParameters();

        $this->assertCount(1, $parameters);

        $type = $parameters[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(ReportManagerInterface::class, $type->getName());
    }

    /**
     * Two payments backed by the minimal customer/quotation/order chain.
     */
    private function seedPayments(): void
    {
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

        $order = Order::query()->create([
            'order_number' => 'ORD-2026-000001',
            'customer_profile_id' => $profile->id,
            'quotation_id' => $quotation->id,
            'status' => OrderStatus::PendingPayment,
            'subtotal' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total_amount' => '100.00',
        ]);

        foreach (['40.00', '55.50'] as $index => $amount) {
            Payment::query()->create([
                'payment_number' => sprintf('PAY-2026-%06d', $index + 1),
                'customer_profile_id' => $profile->id,
                'payable_type' => Order::class,
                'payable_id' => $order->id,
                'status' => PaymentStatus::Initialized,
                'amount' => $amount,
                'currency' => 'USD',
                'gateway' => 'manual',
            ]);
        }
    }
}
