<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollItemDeduction;
use App\Models\PayrollRun;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision 2026-11-03: a Permanent employee's net salary is never below zero because of a Temporary cover:
 *
 *   Permanent net = max(0, Permanent gross - Temporary replacement deduction)
 *
 * The Temporary is still owed salary / 30 x workdays in full. One rule (Payroll\ReplacementDeductionCap) feeds the payroll run, the payment-list preview
 * and download and the payroll workbook. Synthetic data only.
 */
class PermanentNetFloorTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $token;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-20 10:00:00');
        [, $this->token] = $this->superAdminToken();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** @return array{0: EmployeeWorkAssignment, 1: Employee, 2: Employee} */
    private function position(int $dollars = 300): array
    {
        $permanent = $this->makeEmployee('active', 'permanent');
        $assignment = $this->makeAssignment($permanent, $this->makeCompany('Floor Co '.fake()->unique()->word()), null, $dollars, '2026-01-01');

        return [$assignment, $permanent, $this->makeEmployee('active', 'temporary')];
    }

    private function cover(EmployeeWorkAssignment $a, Employee $temp, string $start, string $end): TemporaryReplacement
    {
        return TemporaryReplacement::query()->create([
            'work_assignment_id' => $a->id, 'replacement_employee_id' => $temp->id, 'start_date' => $start, 'end_date' => $end,
            'currency' => 'USD', 'status' => 'ended', 'approved_at' => now(),
        ]);
    }

    private function generate(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertCreated();
    }

    /** @return array<string, array{string, int, int, int}> last covered day, expected deduction / net / not-deducted cents */
    public static function cases(): array
    {
        return [
            'deduction below the gross (12 days)' => ['2026-10-12', 12000, 18000, 0],
            'deduction equal to the gross (30 days)' => ['2026-10-30', 30000, 0, 0],
            'deduction above the gross (31 days)' => ['2026-10-31', 30000, 0, 1000],
        ];
    }

    #[DataProvider('cases')]
    public function test_the_payroll_run_never_takes_a_permanent_below_zero(string $lastDay, int $deducted, int $net, int $capped): void
    {
        [$a, , $temp] = $this->position(300);
        $this->cover($a, $temp, '2026-10-01', $lastDay);

        $this->generate();

        $item = PayrollItem::query()->where('work_assignment_id', $a->id)->sole();
        $this->assertSame(30000, (int) $item->gross_cents, 'the Permanent\'s own pay is the monthly salary of a whole 31-day month');
        $this->assertSame($deducted, (int) $item->replacement_deduction_cents);
        $this->assertSame($net, (int) $item->net_cents);
        $this->assertGreaterThanOrEqual(0, (int) $item->net_cents);

        $deduction = PayrollItemDeduction::query()->where('payroll_item_id', $item->id)->where('kind', 'replacement')->sole();
        $this->assertSame($deducted, (int) $deduction->amount_cents);
        $this->assertSame($capped, (int) ($deduction->detail['not_deducted_cents'] ?? 0));
    }

    public function test_the_temporary_is_still_paid_salary_over_30_times_the_workdays_when_the_deduction_was_capped(): void
    {
        [$a, , $temp] = $this->position(300);
        $cover = $this->cover($a, $temp, '2026-10-01', '2026-10-31');

        $this->generate();

        $deduction = PayrollItemDeduction::query()->where('kind', 'replacement')->sole();
        $this->assertSame(30000, (int) $deduction->amount_cents, 'only 300.00 is taken off the Permanent');
        $this->assertSame(31000, (int) $deduction->detail['amount_cents'], 'the cover itself still costs 31 x 10.00');
        $this->assertTrue($deduction->detail['capped_at_zero_net']);
        $this->withToken($this->token)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/expected-payment")->assertOk()
            ->assertJsonPath('data.expected', '310.00')
            ->assertJsonPath('data.months.0.rate_divisor', 30);
        $this->withToken($this->token)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/cost?period=2026-10")->assertOk()
            ->assertJsonPath('data.amount', '310.00');
    }

    public function test_the_payment_list_its_preview_and_its_download_show_the_same_zero_net_and_the_full_temporary_amount(): void
    {
        [$a, , $temp] = $this->position(300);
        $this->cover($a, $temp, '2026-10-01', '2026-10-31');
        $companyId = $a->client_company_id;

        $this->app['auth']->forgetGuards();
        $preview = $this->withToken($this->token)->getJson('/api/v1/admin/hr/reports/monthly-payment-list/preview?'.http_build_query(['scope' => 'company', 'client_company_id' => $companyId, 'period' => '2026-10']))->assertOk()->json('data');
        $this->assertSame(['0.00', '310.00', '310.00'], [$preview['permanent_total'], $preview['temporary_total'], $preview['combined_total']]);

        $this->app['auth']->forgetGuards();
        $response = $this->withToken($this->token)->get('/api/v1/admin/hr/reports/monthly-payment-list?'.http_build_query(['scope' => 'company', 'client_company_id' => $companyId, 'period' => '2026-10']))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->tempFiles[] = $path;
        $totals = [];

        foreach (IOFactory::load($path)->getSheetByName('Payment list')->toArray(null, true, false, false) as $row) {
            $label = is_string($row[0] ?? null) ? $row[0] : '';

            if (str_contains($label, 'total') || $label === 'Combined total') {
                $totals[$label] = (float) $row[4];
            }
        }

        $this->assertEqualsWithDelta(0.0, $totals['Permanent employees total (this record)'] ?? $totals['Permanent employees total'], 0.0001);
        $this->assertEqualsWithDelta(310.0, $totals['Temporary workers total (not included in the Permanent total)'], 0.0001);
        $this->assertEqualsWithDelta(310.0, $totals['Combined total'], 0.0001);
    }

    public function test_the_payroll_workbook_shows_the_part_that_was_not_deducted_instead_of_a_mismatch(): void
    {
        [$a, , $temp] = $this->position(300);
        $this->cover($a, $temp, '2026-10-01', '2026-10-31');
        $this->generate();
        $run = PayrollRun::query()->where('period', '2026-10')->sole();

        $this->app['auth']->forgetGuards();
        $response = $this->withToken($this->token)->get("/api/v1/admin/hr/payroll-runs/{$run->id}/export")->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->tempFiles[] = $path;
        $sheet = IOFactory::load($path)->getActiveSheet();
        $text = json_encode($sheet->toArray(null, true, false, false));

        $this->assertStringContainsString('310.00 vs 300.00 + 10.00 not deducted', (string) $text);
        $this->assertStringContainsString('OK', (string) $text);
        $this->assertStringNotContainsString('MISMATCH', (string) $text);
    }
}
