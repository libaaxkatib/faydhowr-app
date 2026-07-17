<?php

namespace Tests\Feature\Postgres;

use App\Models\Admin;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportPostgresIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This integration test requires PostgreSQL.');
        }
    }

    public function test_reports_reject_an_unapproved_report_type(): void
    {
        $this->expectException(QueryException::class);

        DB::table('reports')->insert([
            ...$this->reportAttributes(),
            'report_type' => 'revenue',
        ]);
    }

    public function test_reports_reject_an_unapproved_format(): void
    {
        $this->expectException(QueryException::class);

        DB::table('reports')->insert([
            ...$this->reportAttributes(),
            'format' => 'csv',
        ]);
    }

    public function test_reports_require_a_valid_admin_generator(): void
    {
        $this->expectException(QueryException::class);

        DB::table('reports')->insert([
            ...$this->reportAttributes(),
            'generated_by' => 999_999,
        ]);
    }

    public function test_reports_accept_approved_type_and_format_values(): void
    {
        $id = DB::table('reports')->insertGetId([
            ...$this->reportAttributes(),
            'report_type' => 'goods_receipts',
            'format' => 'excel',
            'filters' => json_encode(['supplier_id' => 12]),
        ]);

        $this->assertDatabaseHas('reports', [
            'id' => $id,
            'report_type' => 'goods_receipts',
            'format' => 'excel',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reportAttributes(): array
    {
        return [
            'report_type' => 'bookings',
            'format' => 'json',
            'filters' => null,
            'generated_by' => Admin::factory()->create()->id,
            'generated_at' => now(),
            'created_at' => now(),
        ];
    }
}
