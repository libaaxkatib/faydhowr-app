<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\Hr\Payroll\PayrollRunChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The read-only checks shown beside a payroll run (owner decision 2026-10-29: one HR Manager prepares and finalizes, so the screen helps that person
 * notice what looks wrong). They describe, they never change a run.
 */
class PayrollRunChecksTest extends TestCase
{
    use RefreshDatabase;

    private function payrollRun(string $period, int $netCents, array $items = []): PayrollRun
    {
        $run = PayrollRun::query()->create(['period' => $period, 'status' => 'draft', 'totals' => ['net_cents' => $netCents]]);
        $run->setRelation('items', collect($items));

        return $run;
    }

    private function line(int $assignmentId, int $netCents): PayrollItem
    {
        return (new PayrollItem)->forceFill(['work_assignment_id' => $assignmentId, 'net_cents' => $netCents]);
    }

    public function test_a_normal_month_has_nothing_to_check(): void
    {
        $this->payrollRun('2026-08', 100000);
        $run = $this->payrollRun('2026-09', 110000, [$this->line(1, 55000), $this->line(2, 55000)]);

        $this->assertSame([], PayrollRunChecks::for($run));
    }

    public function test_a_total_that_moves_more_than_a_quarter_is_pointed_out(): void
    {
        $this->payrollRun('2026-08', 100000);

        $higher = PayrollRunChecks::for($this->payrollRun('2026-09', 140000));
        $this->assertCount(1, $higher);
        $this->assertStringContainsString('40% higher than 2026-08', $higher[0]);

        $this->assertStringContainsString('57% lower than 2026-09', PayrollRunChecks::for($this->payrollRun('2026-10', 60000))[0]);
    }

    public function test_the_first_month_with_no_earlier_total_is_not_compared(): void
    {
        $this->payrollRun('2026-08', 0);

        $this->assertSame([], PayrollRunChecks::for($this->payrollRun('2026-09', 500000)));
    }

    public function test_lines_with_zero_or_negative_net_pay_are_listed(): void
    {
        $run = $this->payrollRun('2026-09', 100000, [$this->line(7, 0), $this->line(8, -500), $this->line(9, 5000)]);

        $checks = PayrollRunChecks::for($run);

        $this->assertCount(1, $checks);
        $this->assertStringContainsString('2 payroll line(s)', $checks[0]);
        $this->assertStringContainsString('#7, #8', $checks[0]);
    }

    public function test_the_checks_are_added_to_the_run_view_and_change_nothing_stored(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $this->payrollRun('2026-08', 100000);
        $stored = PayrollRun::query()->create(['period' => '2026-09', 'status' => 'draft', 'totals' => ['net_cents' => 200000, 'item_count' => 0, 'warnings' => ['Generated warning.']]]);

        $response = $this->withToken($admin->createToken('t')->plainTextToken)->getJson("/api/v1/admin/hr/payroll-runs/{$stored->id}")->assertOk();

        $warnings = $response->json('data.warnings');
        $this->assertSame('Generated warning.', $warnings[0]);
        $this->assertStringContainsString('100% higher than 2026-08', $warnings[1]);
        $this->assertSame(['net_cents' => 200000, 'item_count' => 0, 'warnings' => ['Generated warning.']], PayrollRun::query()->find($stored->id)->totals);
    }
}
