<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Actions\Hr\MarkPayrollItemPaidAction;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeePenalty;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\WorkLocation;
use App\Support\Hr\Payroll\MonthlyPaymentReportBuilder;
use App\Support\Hr\Payroll\PaymentNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The monthly Excel payment list: amounts come from the payroll engine (never salary alone), every employee is
 * listed once, a stored payment number always wins over the registration phone (which is used directly, as the Phone Number, only when no number is stored), missing data is flagged, access needs
 * hr.payroll.view AND hr.manage, and downloading changes nothing.
 */
class MonthlyPaymentReportTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const P = '2026-10';

    private const URL = '/api/v1/admin/hr/reports/monthly-payment-list';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /** Laravel caches the first authenticated user for a whole test; reset it so each token really is that user. */
    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function staff(string $status = 'active', ?string $account = null, string $phone = '615000111'): Employee
    {
        $employee = $this->makeEmployee($status, 'permanent', $phone);
        $employee->forceFill(['payment_account_number' => $account])->save();

        return $employee;
    }

    private function company(string $name, string $category = 'company'): ClientCompany
    {
        return $this->makeCompany($name, $category);
    }

    private function workbook(string $token, string $period = self::P, ?int $companyId = null): Spreadsheet
    {
        $response = $this->asToken($token)->get(self::URL.'?period='.$period.($companyId === null ? '' : '&client_company_id='.$companyId))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->tempFiles[] = $path;

        return IOFactory::load($path);
    }

    /** @return list<array<string, mixed>> detail rows keyed by header, from the "Employees" sheet */
    private function detailRows(Spreadsheet $book): array
    {
        $sheet = $book->getSheetByName('Employees');
        $rows = $sheet->toArray(null, true, false, false);
        $headers = $rows[3]; // header is on row 4
        $out = [];
        foreach (array_slice($rows, 4) as $row) {
            // The total line under the table (Total / Company total) is not an employee row.
            if (($row[0] ?? null) === null || in_array($row[0], ['Total', 'Company total'], true)) {
                continue;
            }
            $out[] = array_combine($headers, $row);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function rowFor(Spreadsheet $book, string $employeeNumber): array
    {
        foreach ($this->detailRows($book) as $row) {
            if ($row['Employee number'] === $employeeNumber) {
                return $row;
            }
        }
        $this->fail("No row for {$employeeNumber}");
    }

    public function test_the_payment_amount_is_the_payroll_net_not_the_plain_salary(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->company('Alpha Hospital');
        $joiner = $this->staff('active', '0615990001');
        $this->makeAssignment($joiner, $company, null, 300, '2026-10-11'); // 21 of 31 days => 203.23
        $penalised = $this->staff('active', '0615990002', '615000222');
        $this->makeAssignment($penalised, $company, null, 300, '2026-01-01');
        EmployeePenalty::query()->create(['employee_id' => $penalised->id, 'penalty_date' => '2026-10-05', 'reason' => 'Late', 'deduction_amount' => 15, 'currency' => 'USD', 'payroll_period' => self::P]);

        $book = $this->workbook($token);

        $prorated = $this->rowFor($book, $joiner->employee_number);
        $this->assertEquals(300.0, $prorated['Monthly salary (USD)']);
        $this->assertEquals(203.23, $prorated['Payment amount (USD)']);
        $this->assertEquals(21, $prorated['Days paid']);
        $this->assertEquals(31, $prorated['Days in month']);

        $deducted = $this->rowFor($book, $penalised->employee_number);
        $this->assertEquals(300.0, $deducted['Gross pay (USD)']);
        $this->assertEquals(15.0, $deducted['Deductions (USD)']);
        $this->assertEquals(285.0, $deducted['Payment amount (USD)']);
    }

    public function test_it_uses_the_same_numbers_as_the_payroll_rollup(): void
    {
        [, $token] = $this->superAdminToken();
        $e = $this->staff('active', '0615990003');
        $this->makeAssignment($e, $this->company('Alpha'), null, 450, '2026-10-16');

        $rollup = $this->asToken($token)->getJson('/api/v1/admin/hr/reports/payroll-rollup?period='.self::P)->assertOk();
        $row = $this->rowFor($this->workbook($token), $e->employee_number);

        $this->assertEquals((float) $rollup->json('meta.totals.net_payable'), $row['Payment amount (USD)']);
    }

    public function test_a_stored_payment_number_always_wins_over_the_registration_phone(): void
    {
        [, $token] = $this->superAdminToken();
        $with = $this->staff('active', '0612345678', '615777777');
        $this->makeAssignment($with, $this->company('Alpha'));

        $book = $this->workbook($token);
        $row = $this->rowFor($book, $with->employee_number);

        $this->assertSame('0612345678', $row['Payment / account number'], 'kept as text, leading zero intact');
        $this->assertSame(PaymentNumber::LABEL_RECORDED, $row['Payment number source']);
        $this->assertSame('OK', $row['Data checks']);
        $this->assertStringNotContainsString('615777777', json_encode($book->getSheetByName('Employees')->toArray()), 'the phone is not shown when a number is stored');
    }

    public function test_without_a_stored_number_the_phone_number_is_used_directly_and_is_never_called_a_fallback(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->company('Alpha');
        $without = $this->staff('active', null, '615123456');
        $this->makeAssignment($without, $company);
        $blank = $this->staff('active', '   ', '615654321');
        $this->makeAssignment($blank, $company);

        $book = $this->workbook($token);

        foreach ([[$without, '615123456'], [$blank, '615654321']] as [$employee, $phone]) {
            $row = $this->rowFor($book, $employee->employee_number);
            $this->assertSame($phone, $row['Payment / account number']);
            $this->assertSame(PaymentNumber::LABEL_PHONE, $row['Payment number source']);
            $this->assertSame('Phone Number', $row['Payment number source']);
            $this->assertSame('OK', $row['Data checks'], 'using the Phone Number is normal, not a data problem');
        }
        $summary = $book->getSheetByName('Summary');
        $this->assertSame(0, $summary->getCell('B11')->getValue(), 'a Phone Number is not "missing"');
        $this->assertSame(2, $summary->getCell('B15')->getValue(), 'the employees paid on their Phone Number are counted on their own line');
        // The word "fallback" appears nowhere in the exported payroll output.
        $this->assertStringNotContainsStringIgnoringCase('fallback', json_encode(array_map(fn ($sheet) => $sheet->toArray(), $book->getAllSheets())));
        // Nothing is written back: the stored number stays empty.
        $this->assertNull(Employee::query()->find($without->id)->payment_account_number);
    }

    public function test_when_the_account_number_and_the_phone_are_the_same_that_number_is_used(): void
    {
        [, $token] = $this->superAdminToken();
        $same = $this->staff('active', '0615123456', '0615123456');
        $this->makeAssignment($same, $this->company('Alpha'));

        $row = $this->rowFor($this->workbook($token), $same->employee_number);

        $this->assertSame('0615123456', $row['Payment / account number']);
        $this->assertSame(PaymentNumber::LABEL_RECORDED, $row['Payment number source']);
        $this->assertSame('OK', $row['Data checks']);
    }

    public function test_when_the_account_number_and_the_phone_differ_the_account_number_is_used(): void
    {
        [, $token] = $this->superAdminToken();
        $different = $this->staff('active', '0699000111', '0615123456');
        $this->makeAssignment($different, $this->company('Alpha'));

        $book = $this->workbook($token);
        $row = $this->rowFor($book, $different->employee_number);

        $this->assertSame('0699000111', $row['Payment / account number']);
        $this->assertSame(PaymentNumber::LABEL_RECORDED, $row['Payment number source']);
        $this->assertStringNotContainsString('0615123456', json_encode($book->getSheetByName('Employees')->toArray()), 'the phone is not shown when an Employee Number / Account Number exists');
    }

    public function test_leading_zeros_survive_for_both_the_account_number_and_the_phone_number(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->company('Alpha');
        $account = $this->staff('active', '000123456', '0615000001');
        $this->makeAssignment($account, $company);
        $phoneOnly = $this->staff('active', null, '0610000009');
        $this->makeAssignment($phoneOnly, $company);

        $book = $this->workbook($token);

        $this->assertSame('000123456', $this->rowFor($book, $account->employee_number)['Payment / account number']);
        $this->assertSame('0610000009', $this->rowFor($book, $phoneOnly->employee_number)['Payment / account number']);
        // Both are real text cells in the file, not numbers Excel would shorten.
        $sheet = $book->getSheetByName('Employees');
        $checked = 0;
        foreach ($sheet->toArray(null, true, false, false) as $index => $line) {
            if (in_array($line[2] ?? null, ['000123456', '0610000009'], true)) {
                $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C'.($index + 1))->getDataType());
                $checked++;
            }
        }
        $this->assertSame(2, $checked);
    }

    public function test_the_employee_name_and_the_number_that_person_is_paid_on_sit_side_by_side_and_stay_in_view(): void
    {
        [, $token] = $this->superAdminToken();
        $e = $this->staff('active', '0699000111', '0615123456');
        $e->forceFill(['full_name' => 'Amina Test Person'])->save();
        $this->makeAssignment($e, $this->company('Alpha'));

        $sheet = $this->workbook($token)->getSheetByName('Employees');
        $lines = $sheet->toArray(null, true, false, false);
        $headers = $lines[3];

        $this->assertSame('Employee name', $headers[1]);
        $this->assertSame('Payment / account number', $headers[2], 'the number is the column right after the name');
        $this->assertSame('Payment number source', $headers[3]);
        $this->assertSame('D5', $sheet->getFreezePane(), 'name and number stay in view while scrolling sideways');
        $this->assertSame(['Amina Test Person', '0699000111', PaymentNumber::LABEL_RECORDED], array_slice($lines[4], 1, 3));
        $this->assertSame('Internal employee ID', $headers[15], 'the internal id stays a separate column');
        $this->assertSame((string) $e->id, $lines[4][15]);
    }

    public function test_with_neither_a_payment_number_nor_a_phone_the_number_is_missing_and_never_guessed(): void
    {
        [, $token] = $this->superAdminToken();
        $nobody = $this->staff('active', null, '');
        $this->makeAssignment($nobody, $this->company('Alpha'));

        $row = $this->rowFor($this->workbook($token), $nobody->employee_number);

        $this->assertSame('MISSING', $row['Payment / account number']);
        $this->assertStringContainsString('Missing payment number', $row['Data checks']);
    }

    public function test_an_imported_number_that_differs_from_the_registration_phone_is_kept_and_flagged_for_review(): void
    {
        [, $token] = $this->superAdminToken();
        $imported = $this->staff('active', '0611111111', '615999999');
        $imported->forceFill([
            'payment_account_source' => PaymentNumber::SOURCE_WORKBOOK_PART_ONE,
            'payment_account_source_ref' => 'EMPLOYEE AND COMPANY.xlsx / row 7',
            'payment_account_register_check' => PaymentNumber::CHECK_MISMATCH,
        ])->save();
        $this->makeAssignment($imported, $this->company('Alpha'));

        $row = $this->rowFor($this->workbook($token), $imported->employee_number);

        $this->assertSame('0611111111', $row['Payment / account number'], 'the Excel value is used, not replaced by the phone');
        $this->assertSame(PaymentNumber::LABEL_IMPORTED, $row['Payment number source']);
        $this->assertStringContainsString('differs from the registration phone', $row['Data checks']);
        $this->assertSame((string) $imported->id, $row['Internal employee ID']);
        $this->assertSame($imported->employee_number, $row['Employee number']);
    }

    public function test_a_company_list_holds_only_that_companys_lines_and_its_own_total(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->company('Alpha Hospital');
        $b = $this->company('Beta School');
        $both = $this->staff('active', '0615990010');
        $this->makeAssignment($both, $a, null, 200, '2026-01-01');
        $this->makeAssignment($both, $b, null, 100, '2026-01-01');
        $onlyB = $this->staff('active', '0615990011');
        $this->makeAssignment($onlyB, $b, null, 300, '2026-01-01');
        $before = DB::table('employee_work_assignments')->count();

        $book = $this->workbook($token, self::P, $a->id);

        $rows = $this->detailRows($book);
        $this->assertCount(1, $rows, 'only the people who work for the selected company');
        $this->assertSame($both->employee_number, $rows[0]['Employee number']);
        $this->assertEquals(200.0, $rows[0]['Payment amount (USD)'], 'only the line of the selected company, not 300');
        $this->assertSame('Alpha Hospital', $rows[0]['Company / Guri']);
        $this->assertStringNotContainsString('More than one assignment', $rows[0]['Data checks']);
        $sheet = $book->getSheetByName('Employees');
        $this->assertStringContainsString('Alpha Hospital', (string) $sheet->getCell('A1')->getValue());
        $values = $sheet->toArray(null, true, false, false);
        $totalRow = collect($values)->first(fn (array $r): bool => ($r[0] ?? null) === 'Company total');
        $this->assertNotNull($totalRow);
        $this->assertEquals(200.0, $totalRow[12], 'the total sits under the Payment amount column');
        $this->assertSame(0, $book->getSheetByName('Not in payroll')->getHighestDataRow() > 3 ? 1 : 0, 'a company list has no "not in payroll" rows');
        $this->assertSame($before, DB::table('employee_work_assignments')->count(), 'exporting changes nothing');
        // The all-company list is still available and unchanged.
        $this->assertCount(2, $this->detailRows($this->workbook($token)));
    }

    public function test_an_unknown_company_is_refused(): void
    {
        [, $token] = $this->superAdminToken();

        $this->asToken($token)->get(self::URL.'?period='.self::P.'&client_company_id=999999')->assertStatus(422);
    }

    public function test_an_employee_with_two_assignments_is_listed_and_counted_once(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->company('Alpha Hospital');
        $b = $this->company('Beta School');
        $both = $this->staff('active', '0615990010');
        $this->makeAssignment($both, $a, null, 200, '2026-01-01');
        $this->makeAssignment($both, $b, null, 100, '2026-01-01');
        $solo = $this->staff('active', '0615990011');
        $this->makeAssignment($solo, $a, null, 300, '2026-01-01');

        $book = $this->workbook($token);

        $this->assertCount(2, $this->detailRows($book));
        $row = $this->rowFor($book, $both->employee_number);
        $this->assertEquals(300.0, $row['Payment amount (USD)']);
        $this->assertSame('Alpha Hospital; Beta School', $row['Company / Guri']);
        $this->assertStringContainsString('More than one assignment', $row['Data checks']);
        $this->assertSame('31 + 31', $row['Days paid'], 'each assignment shows its own days, never an added-up 62');

        $report = app(MonthlyPaymentReportBuilder::class)->build(self::P);
        $this->assertSame(2, $report['summary']['employee_count']);
        $this->assertSame(60000, $report['summary']['total_net_cents']);
        $this->assertSame(60000, array_sum(array_column($report['summary']['by_company'], 'net_cents')), 'company rows add up to the total');
        $this->assertSame(
            ['Alpha Hospital' => 2, 'Beta School' => 1],
            array_column($report['summary']['by_company'], 'employee_count', 'company'),
        );
    }

    public function test_totals_by_company_and_category_are_correct(): void
    {
        [, $token] = $this->superAdminToken();
        $hospital = $this->company('Alpha Hospital', 'company');
        $school = $this->company('Beta School', 'company');
        $household = $this->company('Guri Ahmed', 'guri');
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();
        foreach ([[$hospital, 300], [$hospital, 250], [$school, 400], [$household, 120]] as $i => [$company, $salary]) {
            $this->makeAssignment($this->staff('active', '06159900'.(20 + $i)), $company, null, $salary);
        }
        $this->makeAssignment($this->staff('active', '0615990030'), null, $office, 500);

        $report = app(MonthlyPaymentReportBuilder::class)->build(self::P);
        $summary = $report['summary'];

        $this->assertSame(5, $summary['employee_count']);
        $this->assertSame(157000, $summary['total_net_cents']); // 300+250+400+120+500
        $this->assertSame(
            ['Company' => 95000, 'Guri (household)' => 12000, 'Office (internal)' => 50000],
            array_column($summary['by_category'], 'net_cents', 'category'),
        );
        $this->assertSame(
            ['Company' => 3, 'Guri (household)' => 1, 'Office (internal)' => 1],
            array_column($summary['by_category'], 'employee_count', 'category'),
        );
        $byCompany = array_column($summary['by_company'], 'net_cents', 'company');
        $this->assertSame(55000, $byCompany['Alpha Hospital']);
        $this->assertSame(40000, $byCompany['Beta School']);
        $this->assertSame(12000, $byCompany['Guri Ahmed']);
        $this->assertSame(50000, $byCompany['Fayadhowr Office']);

        $sheet = $this->workbook($token)->getSheetByName('Summary');
        $this->assertSame(5, $sheet->getCell('B6')->getValue());
        $this->assertEquals(1570.0, $sheet->getCell('B7')->getValue());
    }

    public function test_missing_salary_and_missing_company_are_flagged_and_left_out_of_the_totals(): void
    {
        [, $token] = $this->superAdminToken();
        $ok = $this->staff('active', '0615990040');
        $this->makeAssignment($ok, $this->company('Alpha'), null, 300);

        $noAssignment = $this->staff('active', '0615990041');
        $noSalary = $this->staff('active', '0615990042');
        EmployeeWorkAssignment::query()->create([
            'employee_id' => $noSalary->id, 'client_company_id' => $this->company('Gamma')->id, 'start_date' => '2026-01-01',
            'salary_amount' => 0, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly', 'status' => 'active',
        ]); // no salary history row at all
        $applicant = $this->staff('applicant');

        $report = app(MonthlyPaymentReportBuilder::class)->build(self::P);

        $this->assertSame(1, $report['summary']['employee_count']);
        $this->assertSame(30000, $report['summary']['total_net_cents']);
        $missing = array_column($report['not_in_payroll'], 'flag', 'employee_number');
        $this->assertSame('Missing company assignment', $missing[$noAssignment->employee_number]);
        $this->assertSame('Missing salary', $missing[$noSalary->employee_number]);
        $this->assertArrayNotHasKey($applicant->employee_number, $missing, 'only Active employees are expected to be paid');
        $this->assertArrayNotHasKey($ok->employee_number, $missing);

        $book = $this->workbook($token);
        $summary = $book->getSheetByName('Summary');
        $this->assertSame(2, $summary->getCell('B14')->getValue());
        $sheetRows = $book->getSheetByName('Not in payroll')->toArray(null, true, false, false);
        $this->assertContains('Missing company assignment', array_column($sheetRows, 3));
        $this->assertContains('Missing salary', array_column($sheetRows, 3));
    }

    public function test_a_zero_salary_assignment_that_still_produces_a_line_is_flagged(): void
    {
        $e = $this->staff('active', '0615990050');
        $assignment = $this->makeAssignment($e, $this->company('Alpha'), null, 300);
        $assignment->salaryHistories()->update(['amount' => 0]);

        $report = app(MonthlyPaymentReportBuilder::class)->build(self::P);

        $flags = array_column($report['rows'], 'flags', 'employee_number');
        $this->assertContains('Missing salary', $flags[$e->employee_number] ?? []);
    }

    public function test_payment_status_is_only_shown_when_it_is_actually_recorded(): void
    {
        [$admin, $token] = $this->superAdminToken();
        $paid = $this->staff('active', '0615990060');
        $unpaid = $this->staff('active', '0615990061');
        $company = $this->company('Alpha');
        $this->makeAssignment($paid, $company);
        $this->makeAssignment($unpaid, $company);

        $estimate = $this->workbook($token);
        $this->assertSame('Not recorded (payroll not finalized)', $this->rowFor($estimate, $paid->employee_number)['Payment status']);
        $this->assertStringContainsString('ESTIMATE', (string) $estimate->getSheetByName('Summary')->getCell('B4')->getValue());

        $run = $this->asToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => self::P])->assertCreated()->json('data.id');
        foreach (['review', 'approve', 'finalize'] as $step) {
            $this->asToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/{$step}")->assertOk();
        }
        $item = PayrollItem::query()->where('employee_id', $paid->id)->firstOrFail();
        app(MarkPayrollItemPaidAction::class)->handle($item, '2026-11-02', $admin);

        $final = $this->workbook($token);
        $this->assertSame('Paid', $this->rowFor($final, $paid->employee_number)['Payment status']);
        $this->assertSame('Not paid', $this->rowFor($final, $unpaid->employee_number)['Payment status']);
        $this->assertStringContainsString('finalized', (string) $final->getSheetByName('Summary')->getCell('B4')->getValue());
    }

    public function test_the_report_follows_the_stored_run_once_it_is_approved(): void
    {
        [, $token] = $this->superAdminToken();
        $e = $this->staff('active', '0615990070');
        $assignment = $this->makeAssignment($e, $this->company('Alpha'), null, 300);
        $run = $this->asToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => self::P])->assertCreated()->json('data.id');
        foreach (['review', 'approve'] as $step) {
            $this->asToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/{$step}")->assertOk();
        }
        // A raise after approval must not change the approved month in the report.
        $assignment->salaryHistories()->create(['effective_from' => '2026-10-02', 'amount' => 900, 'currency' => 'USD', 'frequency' => 'monthly']);

        $this->assertEquals(300.0, $this->rowFor($this->workbook($token), $e->employee_number)['Payment amount (USD)']);
    }

    public function test_the_download_is_private_and_never_cacheable(): void
    {
        [, $token] = $this->superAdminToken();
        $e = $this->staff('active', '0615990075');
        $this->makeAssignment($e, $this->company('Alpha'));

        $response = $this->asToken($token)->get(self::URL.'?period='.self::P)->assertOk();
        $this->tempFiles[] = $response->baseResponse->getFile()->getPathname();

        $header = (string) $response->headers->get('Cache-Control');
        $this->assertSame(['no-store', 'private'], collect(explode(',', $header))->map(fn (string $d): string => trim($d))->sort()->values()->all(), "got: {$header}");
        $this->assertStringNotContainsString('public', $header);
        $this->assertStringContainsString('payment-list-'.self::P.'.xlsx', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_downloading_changes_nothing_and_leaves_no_payment_number_in_the_audit_log(): void
    {
        [, $token] = $this->superAdminToken();
        $e = $this->staff('active', '0615990080');
        $this->makeAssignment($e, $this->company('Alpha'));
        $before = ['runs' => DB::table('payroll_runs')->count(), 'items' => DB::table('payroll_items')->count(), 'payments' => DB::table('employee_payments')->count(), 'assignments' => DB::table('employee_work_assignments')->count()];
        $statuses = DB::table('employees')->pluck('status', 'id')->all();

        $this->workbook($token);

        $this->assertSame($before, ['runs' => DB::table('payroll_runs')->count(), 'items' => DB::table('payroll_items')->count(), 'payments' => DB::table('employee_payments')->count(), 'assignments' => DB::table('employee_work_assignments')->count()]);
        $this->assertSame($statuses, DB::table('employees')->pluck('status', 'id')->all());

        $entry = DB::table('audit_logs')->where('action', 'export')->first();
        $this->assertNotNull($entry);
        $this->assertStringNotContainsString('0615990080', json_encode($entry));
        $this->assertStringContainsString(self::P, $entry->description);
    }

    public function test_payment_numbers_do_not_leak_into_other_responses(): void
    {
        [, $token] = $this->superAdminToken();
        $e = $this->staff('active', '0615990090');
        $this->makeAssignment($e, $this->company('Alpha'));

        $viewer = $this->tokenWithOnly(['hr.view', 'hr.dashboard.view', 'hr.payroll.view', 'hr.reports.view']);
        foreach (['/api/v1/admin/hr/employees', '/api/v1/admin/hr/reports/payroll-rollup?period='.self::P, '/api/v1/admin/hr/payroll-runs', '/api/v1/admin/hr/client-companies', '/api/v1/admin/hr/dashboard'] as $url) {
            $this->assertStringNotContainsString('0615990090', $this->asToken($viewer)->getJson($url)->assertOk()->getContent(), $url);
        }
        $this->assertStringNotContainsString('0615990090', $this->asToken($token)->getJson('/api/v1/admin/hr/employees')->getContent().'', 'the list never includes it; only the single-employee view does, for hr.manage');
    }

    public function test_only_payroll_viewers_who_can_also_manage_hr_may_download(): void
    {
        $e = $this->staff('active', '0615990100');
        $this->makeAssignment($e, $this->company('Alpha'));
        $url = self::URL.'?period='.self::P;

        $this->getJson($url)->assertUnauthorized();
        $this->asToken($this->noPermissionToken())->getJson($url)->assertForbidden();
        $this->asToken($this->tokenWithOnly(['hr.view']))->getJson($url)->assertForbidden();
        $this->asToken($this->tokenWithOnly(['hr.view', 'hr.reports.view']))->getJson($url)->assertForbidden();
        $this->asToken($this->tokenWithOnly(['hr.payroll.view']))->getJson($url)->assertForbidden();
        $this->asToken($this->tokenWithOnly(['hr.manage']))->getJson($url)->assertForbidden();
        $this->asToken($this->tokenWithOnly(['hr.payroll.manage', 'hr.payroll.approve']))->getJson($url)->assertForbidden();

        $this->asToken($this->tokenWithOnly(['hr.payroll.view', 'hr.manage']))->get($url)->assertOk();
        $this->asToken($this->superAdminToken()[1])->get($url)->assertOk();
    }

    public function test_the_month_must_be_a_valid_year_month(): void
    {
        [, $token] = $this->superAdminToken();

        foreach (['', '?period=', '?period=2026-13', '?period=2026-1', '?period=October', '?period=2026-10-01'] as $query) {
            $this->asToken($token)->getJson(self::URL.$query)->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');
        }
    }

    public function test_an_empty_month_still_gives_a_valid_workbook_with_all_sheets_and_filters(): void
    {
        [, $token] = $this->superAdminToken();

        $book = $this->workbook($token, '2026-03');

        $this->assertSame(['Summary', 'Employees', 'Not in payroll'], $book->getSheetNames());
        $this->assertSame(0, $book->getSheetByName('Summary')->getCell('B6')->getValue());
        $this->assertNotNull($book->getSheetByName('Employees')->getAutoFilter()->getRange());
    }

    public function test_the_workbook_has_the_agreed_columns_filters_frozen_header_and_readable_widths(): void
    {
        [, $token] = $this->superAdminToken();
        $e = $this->staff('active', '0615990110');
        $this->makeAssignment($e, $this->company('Alpha'));

        $sheet = $this->workbook($token)->getSheetByName('Employees');

        $this->assertSame([
            'Employee number', 'Employee name', 'Payment / account number', 'Payment number source',
            'Company / Guri', 'Company category', 'Monthly salary (USD)',
            'Days paid', 'Days in month', 'Gross pay (USD)', 'Deductions (USD)', 'Adjustments (USD)',
            'Payment amount (USD)', 'Payment status', 'Data checks', 'Internal employee ID',
        ], array_slice($sheet->toArray(null, true, false, false)[3], 0, 16));
        $this->assertSame('A4:P5', $sheet->getAutoFilter()->getRange());
        $this->assertSame('D5', $sheet->getFreezePane());
        foreach (['B', 'C', 'D', 'E', 'O'] as $col) {
            $this->assertGreaterThanOrEqual(26, $sheet->getColumnDimension($col)->getWidth());
        }
    }

    public function test_formula_looking_names_are_written_as_text(): void
    {
        [, $token] = $this->superAdminToken();
        $e = $this->staff('active', '=1+1');
        $e->forceFill(['full_name' => '=HYPERLINK("http://x","hi")'])->save();
        $this->makeAssignment($e, $this->company('+Alpha'));

        $row = $this->rowFor($this->workbook($token), $e->employee_number);

        $this->assertSame('=HYPERLINK("http://x","hi")', $row['Employee name']);
        $this->assertSame('=1+1', $row['Payment / account number']);
        $this->assertSame('+Alpha', $row['Company / Guri']);
    }
}
