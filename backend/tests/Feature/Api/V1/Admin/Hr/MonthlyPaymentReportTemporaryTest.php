<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollRun;
use App\Models\TemporaryReplacement;
use App\Models\TemporaryReplacementPayment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The monthly payment list with Temporary workers (owner decision of 2026-10-27): each Temporary who covered a Permanent employee's position in the
 * month is listed directly beneath that Permanent, linked by the recorded cover (never by name), with the cover's entitlement for the month, what was
 * paid and what is still owed. The Permanent's own figures and the existing totals do not change, nobody is counted twice, and the Temporary amounts
 * and the amounts deducted from the Permanents agree to the cent. Synthetic data only.
 */
class MonthlyPaymentReportTemporaryTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/hr/reports/monthly-payment-list';

    private const P = '2026-10';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-12-15 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** @return array{0: Employee, 1: EmployeeWorkAssignment, 2: ClientCompany} a Permanent employee holding a position of the given monthly salary */
    private function position(int $dollars = 600, ?ClientCompany $company = null): array
    {
        $permanent = $this->makeEmployee('active', 'permanent');
        $permanent->forceFill(['payment_account_number' => 'P'.fake()->unique()->numerify('#######')])->save();
        $company ??= $this->makeCompany(fake()->unique()->company());

        return [$permanent, $this->makeAssignment($permanent, $company, null, $dollars, '2026-01-01'), $company];
    }

    private function temporary(?string $name = null): Employee
    {
        $temporary = $this->makeEmployee('active', 'temporary');
        $temporary->forceFill(['payment_account_number' => 'T'.fake()->unique()->numerify('#######'), 'full_name' => $name ?? $temporary->full_name])->save();

        return $temporary;
    }

    private function cover(EmployeeWorkAssignment $position, Employee $temporary, string $start, ?string $end, bool $approved = true): TemporaryReplacement
    {
        return TemporaryReplacement::query()->create([
            'work_assignment_id' => $position->id, 'replacement_employee_id' => $temporary->id, 'start_date' => $start, 'end_date' => $end,
            'currency' => 'USD', 'status' => $end === null ? 'active' : 'ended', 'approved_at' => $approved ? now() : null,
        ]);
    }

    private function pay(TemporaryReplacement $cover, string $amount, string $date): void
    {
        TemporaryReplacementPayment::query()->create([
            'temporary_replacement_id' => $cover->id, 'payment_date' => $date, 'amount' => $amount, 'paid_by' => Admin::factory()->create()->id,
        ]);
    }

    private function book(string $token, string $period = self::P, ?int $companyId = null): Spreadsheet
    {
        $this->app['auth']->forgetGuards();
        $response = $this->withToken($token)->get(self::URL.'?period='.$period.($companyId === null ? '' : '&client_company_id='.$companyId))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->tempFiles[] = $path;

        return IOFactory::load($path);
    }

    /** @return list<array<string, mixed>> the employee rows of the Employees sheet in sheet order, keyed by header */
    private function rows(Spreadsheet $book): array
    {
        $table = $book->getSheetByName('Employees')->toArray(null, true, false, false);
        $headers = $table[3];
        $out = [];

        foreach (array_slice($table, 4) as $row) {
            if (($row[0] ?? null) === null || in_array($row[0], ['Total', 'Company total'], true) || str_contains((string) $row[0], 'Temporary workers total') || str_contains((string) $row[0], 'including Temporary workers')) {
                continue;
            }

            $out[] = array_combine($headers, $row);
        }

        return $out;
    }

    /** @return array<string, mixed> the Summary sheet as label => value */
    private function summary(Spreadsheet $book): array
    {
        $out = [];

        foreach ($book->getSheetByName('Summary')->toArray(null, true, false, false) as $row) {
            if (($row[0] ?? null) !== null && ($row[1] ?? null) !== null) {
                $out[(string) $row[0]] = $row[1];
            }
        }

        return $out;
    }

    private function indexOf(array $rows, Employee $employee): int
    {
        foreach ($rows as $index => $row) {
            if ($row['Employee number'] === $employee->employee_number) {
                return $index;
            }
        }

        $this->fail("No row for {$employee->employee_number}");
    }

    public function test_the_temporary_is_listed_directly_beneath_the_permanent_they_covered_with_the_amounts_to_the_cent(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent, $position] = $this->position(600);
        $other = $this->position(500)[0];
        $temporary = $this->temporary();
        $this->cover($position, $temporary, '2026-10-11', '2026-10-20');

        $rows = $this->rows($this->book($token));
        $at = $this->indexOf($rows, $permanent);

        $this->assertSame($temporary->employee_number, $rows[$at + 1]['Employee number'], 'the Temporary is in the very next row');
        $this->assertSame(3, count($rows), 'two Permanent employees and one Temporary: nobody twice');
        $this->assertSame(['Permanent', 'Temporary'], [$rows[$at]['Row type'], $rows[$at + 1]['Row type']]);
        $this->assertSame('Covers: '.$permanent->employee_number.' '.$permanent->full_name, $rows[$at + 1]['Linked employee']);
        $this->assertSame('Covered by: '.$temporary->employee_number.' '.$temporary->full_name, $rows[$at]['Linked employee']);
        $this->assertSame('2026-10-11 to 2026-10-20', $rows[$at + 1]['Cover period']);

        // 600.00 x 10 / 31 = 193.5483... -> 193.55, rounded once. The Permanent keeps 600.00 - 193.55 = 406.45.
        $this->assertEqualsWithDelta(193.55, (float) $rows[$at + 1]['Payment amount (USD)'], 0.0001);
        $this->assertEqualsWithDelta(193.55, (float) $rows[$at + 1]['Gross pay (USD)'], 0.0001);
        $this->assertSame([10, 31], [$rows[$at + 1]['Days paid'], $rows[$at + 1]['Days in month']]);
        $this->assertEqualsWithDelta(406.45, (float) $rows[$at]['Payment amount (USD)'], 0.0001);
        $this->assertSame('n/a (Temporary)', $rows[$at + 1]['Monthly salary (USD)'], 'the covered Permanent\'s salary is not shown as the Temporary\'s own');
        $this->assertSame($temporary->payment_account_number, $rows[$at + 1]['Payment / account number']);
        $this->assertSame([], array_filter([$rows[$at]['Employee number'] === $rows[$at + 1]['Employee number']]), 'one row per cover, not a copy of the Permanent line');
    }

    public function test_nothing_is_counted_twice_and_the_totals_reconcile_to_the_cent(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position(600);
        $this->cover($position, $this->temporary(), '2026-10-11', '2026-10-20');
        $this->cover($position, $this->temporary(), '2026-10-21', '2026-10-25');
        $book = $this->book($token);
        $rows = $this->rows($book);
        $summary = $this->summary($book);

        $money = fn (string $column) => array_sum(array_map(fn (array $r): float => (float) $r[$column], $rows));
        // 600 x 10/31 = 193.55 and 600 x 5/31 = 96.77; the Permanent is charged exactly those two figures: 600 - 193.55 - 96.77 = 309.68.
        $this->assertEqualsWithDelta(193.55 + 96.77, $money('Gross pay (USD)') - 600.00, 0.0001);
        $this->assertEqualsWithDelta(600.00, $money('Payment amount (USD)'), 0.0001, 'the Permanent\'s net plus what the Temporaries earn is exactly the covered salary');
        $this->assertEqualsWithDelta(309.68, (float) $rows[0]['Payment amount (USD)'], 0.0001);
        $this->assertSame(1, $summary['Employees paid (each counted once)'], 'the Temporary workers are not payroll employees');
        $this->assertEqualsWithDelta(309.68, (float) $summary['Total payment amount (USD)'], 0.0001, 'the existing total is the Permanent employees\' own');
        $this->assertEqualsWithDelta(290.32, (float) $summary['Temporary amount earned this month (USD)'], 0.0001);
        $this->assertEqualsWithDelta(290.32, (float) $summary['Deducted from the Permanent employees for these covers (USD)'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $summary['Earned but not deducted (cover not approved, or days already unpaid leave) (USD)'], 0.0001);
        $this->assertSame('Yes', $summary['The two deduction figures agree']);
        $this->assertEqualsWithDelta(600.00, (float) $summary['Total payment amount including Temporary workers (USD)'], 0.0001);
    }

    public function test_an_unapproved_cover_is_listed_and_flagged_but_nothing_is_deducted_from_the_permanent(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position(600);
        $this->cover($position, $this->temporary(), '2026-10-11', '2026-10-20', false);
        $book = $this->book($token);
        $rows = $this->rows($book);
        $summary = $this->summary($book);

        $this->assertEqualsWithDelta(600.00, (float) $rows[0]['Payment amount (USD)'], 0.0001, 'no approval, no deduction (existing rule)');
        $this->assertEqualsWithDelta(193.55, (float) $rows[1]['Payment amount (USD)'], 0.0001);
        $this->assertStringContainsString('Cover not approved', $rows[1]['Data checks']);
        $this->assertEqualsWithDelta(193.55, (float) $summary['Earned but not deducted (cover not approved, or days already unpaid leave) (USD)'], 0.0001);
    }

    public function test_what_was_paid_up_to_the_end_of_the_month_and_the_balance_are_shown(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position(600);
        $temporary = $this->temporary();
        $cover = $this->cover($position, $temporary, '2026-10-11', '2026-10-20');
        $this->pay($cover, '100.00', '2026-10-25');
        $this->pay($cover, '50.00', '2026-11-02'); // after the month: not part of the position at its end

        $row = $this->rows($this->book($token))[1];

        $this->assertEqualsWithDelta(100.00, (float) $row['Paid to date (USD)'], 0.0001);
        $this->assertEqualsWithDelta(93.55, (float) $row['Balance (USD)'], 0.0001);
        $this->assertSame('Partly paid', $row['Payment status']);

        $this->pay($cover, '93.55', '2026-10-30');
        $row = $this->rows($this->book($token))[1];
        $this->assertEqualsWithDelta(0.0, (float) $row['Balance (USD)'], 0.0001);
        $this->assertSame('Paid', $row['Payment status']);

        // The cover has no day in November, so only the Permanent is listed there.
        $this->assertSame(['Permanent'], array_column($this->rows($this->book($token, '2026-11')), 'Row type'));
    }

    public function test_the_balance_of_a_later_month_accumulates_over_the_cover_and_a_straddling_cover_shows_each_months_days(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position(310);
        $cover = $this->cover($position, $this->temporary(), '2026-09-28', '2026-10-03');
        $this->pay($cover, '31.00', '2026-09-30');

        // September: 3 days of 30 at 310 = 31.00 (paid in full). October: 3 days of 31 = 30.00; the balance at the end of October is 61.00 - 31.00.
        $september = $this->rows($this->book($token, '2026-09'))[1];
        $october = $this->rows($this->book($token, '2026-10'))[1];

        $this->assertEqualsWithDelta(31.00, (float) $september['Payment amount (USD)'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $september['Balance (USD)'], 0.0001);
        $this->assertEqualsWithDelta(30.00, (float) $october['Payment amount (USD)'], 0.0001);
        $this->assertEqualsWithDelta(30.00, (float) $october['Balance (USD)'], 0.0001);
        $this->assertSame(3, $october['Days paid']);
    }

    public function test_several_covers_of_one_position_and_one_temporary_on_two_positions_each_get_their_own_row_under_the_right_permanent(): void
    {
        [, $token] = $this->superAdminToken();
        [$firstPermanent, $first] = $this->position(600);
        [$secondPermanent, $second] = $this->position(300);
        $shared = $this->temporary();
        $this->cover($first, $shared, '2026-10-01', '2026-10-10');
        $this->cover($second, $shared, '2026-10-11', '2026-10-20');
        $later = $this->temporary();
        $this->cover($first, $later, '2026-10-21', '2026-10-31');

        $book = $this->book($token);
        $rows = $this->rows($book);
        $a = $this->indexOf($rows, $firstPermanent);
        $b = $this->indexOf($rows, $secondPermanent);

        $this->assertCount(5, $rows);
        // Each Permanent is followed by their own covers, oldest first.
        $this->assertSame([$shared->employee_number, $later->employee_number], [$rows[$a + 1]['Employee number'], $rows[$a + 2]['Employee number']]);
        $this->assertSame($shared->employee_number, $rows[$b + 1]['Employee number']);
        $this->assertSame('Covers: '.$secondPermanent->employee_number.' '.$secondPermanent->full_name, $rows[$b + 1]['Linked employee']);
        $summary = $this->summary($book);
        $this->assertSame(3, $summary['Covers with days in this month']);
        $this->assertSame(2, $summary['Temporary workers'], 'one person on two positions is one Temporary worker');
    }

    public function test_with_an_approved_and_finalized_run_the_stored_permanent_lines_and_the_temporary_rows_still_reconcile(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position(600);
        $this->cover($position, $this->temporary(), '2026-10-11', '2026-10-20');

        $run = $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => self::P])->assertCreated()->json('data.id');
        foreach (['review', 'approve', 'finalize'] as $step) {
            $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/{$step}")->assertOk();
        }

        $book = $this->book($token);
        $rows = $this->rows($book);
        $summary = $this->summary($book);

        $this->assertStringContainsString('Payroll run finalized', $summary['Amounts based on']);
        $this->assertEqualsWithDelta(406.45, (float) $rows[0]['Payment amount (USD)'], 0.0001, 'the stored line');
        $this->assertEqualsWithDelta(193.55, (float) $rows[1]['Payment amount (USD)'], 0.0001);
        $this->assertEqualsWithDelta(193.55, (float) $summary['Replacement deductions on the Permanent lines above (USD)'], 0.0001);
        $this->assertSame('Yes', $summary['The two deduction figures agree']);
        $this->assertEqualsWithDelta(600.00, (float) $summary['Total payment amount including Temporary workers (USD)'], 0.0001);
    }

    public function test_covers_outside_the_month_are_not_listed(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position(600);
        $this->cover($position, $this->temporary(), '2026-09-01', '2026-09-30');
        $this->cover($position, $this->temporary(), '2026-11-01', '2026-11-30');

        $this->assertCount(1, $this->rows($this->book($token)), 'only the Permanent');
    }

    public function test_a_company_list_holds_only_that_companys_covers(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanentA, $a, $companyA] = $this->position(600);
        [, $b] = $this->position(600);
        $inA = $this->temporary();
        $inB = $this->temporary();
        $this->cover($a, $inA, '2026-10-01', '2026-10-10');
        $this->cover($b, $inB, '2026-10-01', '2026-10-10');

        $book = $this->book($token, self::P, $companyA->id);
        $rows = $this->rows($book);

        $this->assertSame([$permanentA->employee_number, $inA->employee_number], array_column($rows, 'Employee number'));
        $this->assertEqualsWithDelta(193.55, (float) $this->summary($book)['Temporary amount earned this month (USD)'], 0.0001);
    }

    public function test_the_columns_already_in_the_list_are_not_changed_and_a_month_without_covers_has_no_temporary_lines(): void
    {
        [, $token] = $this->superAdminToken();
        [$permanent] = $this->position(600);

        $book = $this->book($token);
        $rows = $this->rows($book);

        $this->assertSame(['Permanent'], array_column($rows, 'Row type'));
        $this->assertSame(array_slice(array_keys($rows[0]), 0, 16), [
            'Employee number', 'Employee name', 'Payment / account number', 'Payment number source', 'Company / Guri', 'Company category', 'Monthly salary (USD)',
            'Days paid', 'Days in month', 'Gross pay (USD)', 'Deductions (USD)', 'Adjustments (USD)', 'Payment amount (USD)', 'Payment status', 'Data checks', 'Internal employee ID',
        ]);
        $this->assertSame($permanent->employee_number, $rows[0]['Employee number']);
        $this->assertArrayNotHasKey('Temporary workers (paid through their cover, not through a payroll line)', $this->summary($book));
    }

    public function test_the_workbook_writes_the_temporary_amounts_as_numbers_and_names_as_text(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position(600);
        $this->cover($position, $this->temporary('=HYPERLINK("http://x","hi")'), '2026-10-11', '2026-10-20');

        $sheet = $this->book($token)->getSheetByName('Employees');
        $row = 6; // header on row 4, the Permanent on 5, the Temporary on 6

        $this->assertSame('=HYPERLINK("http://x","hi")', (string) $sheet->getCell("B{$row}")->getValue());
        $this->assertSame('s', $sheet->getCell("B{$row}")->getDataType());
        $this->assertSame('n', $sheet->getCell("M{$row}")->getDataType());
        $this->assertSame('#,##0.00', $sheet->getStyle("M{$row}")->getNumberFormat()->getFormatCode());
        $this->assertSame('n', $sheet->getCell("U{$row}")->getDataType());
        $this->assertSame('A4:U6', $sheet->getAutoFilter()->getRange());
        // The line under the table keeps the Permanent total and adds the Temporary total and the grand total, each a number.
        $this->assertSame('Total', (string) $sheet->getCell('A8')->getValue());
        $this->assertSame('Temporary workers total (not included in the total above)', (string) $sheet->getCell('A9')->getValue());
        $this->assertEqualsWithDelta(193.55, (float) $sheet->getCell('M9')->getValue(), 0.0001);
        $this->assertEqualsWithDelta(600.00, (float) $sheet->getCell('M10')->getValue(), 0.0001);
    }

    public function test_building_the_list_writes_nothing_and_creates_no_payroll_run_or_payment(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position(600);
        $cover = $this->cover($position, $this->temporary(), '2026-10-11', '2026-10-20');
        $this->pay($cover, '10.00', '2026-10-25');
        $before = [PayrollRun::query()->count(), TemporaryReplacementPayment::query()->count(), TemporaryReplacement::query()->count(), $cover->fresh()->updated_at->toIso8601String()];

        $this->book($token);

        $this->assertSame($before, [PayrollRun::query()->count(), TemporaryReplacementPayment::query()->count(), TemporaryReplacement::query()->count(), $cover->fresh()->updated_at->toIso8601String()]);
    }

    public function test_a_temporary_without_a_payment_number_is_flagged_like_any_other_employee(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position(600);
        $temporary = $this->temporary();
        $temporary->forceFill(['payment_account_number' => null, 'phone' => ''])->save();
        $this->cover($position, $temporary, '2026-10-11', '2026-10-20');

        $row = $this->rows($this->book($token))[1];

        $this->assertSame('MISSING', $row['Payment / account number']);
        $this->assertStringContainsString('Missing payment number', $row['Data checks']);
    }
}
