<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The read-only preview shown before the payment-list download: for the same month and selection it must give the row count, the Permanent / Temporary /
 * combined totals and the number of rows without a payment number that the downloaded workbook really contains, and nothing else (no names, no numbers).
 * Synthetic data only.
 */
class MonthlyPaymentListPreviewTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const LIST = '/api/v1/admin/hr/reports/monthly-payment-list';

    private const PREVIEW = '/api/v1/admin/hr/reports/monthly-payment-list/preview';

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

    /** @return array{0: Employee, 1: EmployeeWorkAssignment} */
    private function permanent(ClientCompany $company, int $dollars = 600, ?string $number = 'auto', ?string $phone = null): array
    {
        $employee = $this->makeEmployee('active', 'permanent');
        $employee->forceFill(['payment_account_number' => $number === 'auto' ? 'P'.fake()->unique()->numerify('#######') : $number, 'phone' => $phone ?? $employee->phone])->save();

        return [$employee, $this->makeAssignment($employee, $company, null, $dollars, '2026-01-01')];
    }

    private function temporary(?string $number = 'auto'): Employee
    {
        $employee = $this->makeEmployee('active', 'temporary');
        $employee->forceFill(['payment_account_number' => $number === 'auto' ? 'T'.fake()->unique()->numerify('#######') : $number])->save();

        return $employee;
    }

    private function cover(EmployeeWorkAssignment $position, Employee $temporary, string $start = '2026-10-01', ?string $end = '2026-10-10'): TemporaryReplacement
    {
        return TemporaryReplacement::query()->create([
            'work_assignment_id' => $position->id, 'replacement_employee_id' => $temporary->id, 'start_date' => $start, 'end_date' => $end,
            'currency' => 'USD', 'status' => 'ended', 'approved_at' => now(),
        ]);
    }

    private function preview(string $token, array $query)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson(self::PREVIEW.'?'.http_build_query($query + ['period' => self::P]));
    }

    private function book(string $token, array $query): Spreadsheet
    {
        $this->app['auth']->forgetGuards();
        $response = $this->withToken($token)->get(self::LIST.'?'.http_build_query($query + ['period' => self::P]))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->tempFiles[] = $path;

        return IOFactory::load($path);
    }

    /**
     * What the workbook itself says: the number of rows of the Payment list sheet, the three totals written under it and the blank NUMBER cells.
     *
     * @return array{rows: int, permanent: float, temporary: float, combined: float, missing: int}
     */
    private function workbookFacts(Spreadsheet $book): array
    {
        $sheet = $book->getSheetByName('Payment list');
        $rows = 0;
        $missing = 0;
        $totals = [];

        foreach ($sheet->toArray(null, true, false, false) as $index => $row) {
            $line = $index + 1;
            $label = is_string($row[0] ?? null) ? $row[0] : '';

            if ($line >= 5 && $label !== '' && ! str_contains($label, 'total') && $label !== 'Combined total' && ! str_contains($label, 'have no payment number') && $label !== 'No payments for this month.') {
                $rows++;
                $number = $sheet->getCell('B'.$line)->getValue();
                $missing += ($number === null || $number === '') ? 1 : 0;
            }
            if ($line >= 5 && (str_contains($label, 'total') || $label === 'Combined total') && ! str_contains($label, 'have no payment number')) {
                $totals[$label] = (float) $sheet->getCell('E'.$line)->getValue();
            }
        }

        return [
            'rows' => $rows,
            'permanent' => $totals['Permanent employees total (this record)'] ?? $totals['Permanent employees total'],
            'temporary' => $totals['Temporary workers total (not included in the Permanent total)'],
            'combined' => $totals['Combined total'],
            'missing' => $missing,
        ];
    }

    /** @return array{0: ClientCompany, 1: ClientCompany, 2: ClientCompany, 3: ClientCompany} */
    private function world(): array
    {
        return [
            $this->makeCompany('Company A '.fake()->unique()->word()),
            $this->makeCompany('Company B '.fake()->unique()->word()),
            $this->makeCompany('Guri A '.fake()->unique()->word(), 'guri'),
            $this->makeCompany('Guri B '.fake()->unique()->word(), 'guri'),
        ];
    }

    // ------------------------------------------------------------------------------------------------------ totals and the workbook

    public function test_the_worked_example_is_previewed_as_in_the_workbook(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Example Company B');
        [, $position] = $this->permanent($company, 600);
        $this->cover($position, $this->temporary());

        $data = $this->preview($token, ['scope' => 'company', 'client_company_id' => $company->id])->assertOk()->json('data');

        $this->assertSame(['id' => $company->id, 'name' => 'Example Company B', 'kind' => 'company'], $data['record']);
        $this->assertSame([self::P, 'company', 2, 1, 1], [$data['period'], $data['scope'], $data['row_count'], $data['permanent_count'], $data['temporary_count']]);
        $this->assertSame(['406.45', '193.55', '600.00', 'USD'], [$data['permanent_total'], $data['temporary_total'], $data['combined_total'], $data['currency']]);
        $this->assertSame(0, $data['missing_payment_number_count']);
        $this->assertTrue($data['is_estimate'], 'no approved run exists: the amounts are an estimate, as the workbook says');
    }

    public function test_the_preview_equals_the_workbook_for_a_company_a_guri_and_all(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $b, $ga, $gb] = $this->world();
        [, $positionA] = $this->permanent($a, 600);
        $this->permanent($a, 450, null, '0000000011');          // phone fallback: not missing
        $this->permanent($a, 250, null, ' ');                    // neither: missing
        [, $positionB] = $this->permanent($b, 300);
        $this->permanent($ga, 500);
        $this->permanent($gb, 700, null, '   ');                 // neither: missing
        $noNumber = $this->temporary(null);
        $noNumber->forceFill(['phone' => '  '])->save();
        $this->cover($positionA, $noNumber);                     // a Temporary without any number: missing
        $this->cover($positionB, $this->temporary(), '2026-10-11', '2026-10-20');

        $selections = [
            'company A' => ['scope' => 'company', 'client_company_id' => $a->id],
            'company B' => ['scope' => 'company', 'client_company_id' => $b->id],
            'guri A' => ['scope' => 'guri', 'client_company_id' => $ga->id],
            'guri B' => ['scope' => 'guri', 'client_company_id' => $gb->id],
            'all' => ['scope' => 'all'],
        ];

        foreach ($selections as $label => $query) {
            $data = $this->preview($token, $query)->assertOk()->json('data');
            $facts = $this->workbookFacts($this->book($token, $query));

            $this->assertSame($facts['rows'], $data['row_count'], "{$label}: rows");
            $this->assertEqualsWithDelta($facts['permanent'], (float) $data['permanent_total'], 0.0001, "{$label}: Permanent total");
            $this->assertEqualsWithDelta($facts['temporary'], (float) $data['temporary_total'], 0.0001, "{$label}: Temporary total");
            $this->assertEqualsWithDelta($facts['combined'], (float) $data['combined_total'], 0.0001, "{$label}: combined total");
            $this->assertSame($facts['missing'], $data['missing_payment_number_count'], "{$label}: missing numbers");
            $this->assertEqualsWithDelta((float) $data['permanent_total'] + (float) $data['temporary_total'], (float) $data['combined_total'], 0.0001, "{$label}: the combined total is the sum");
        }

        $this->assertSame(2, $this->preview($token, $selections['company A'])->json('data.missing_payment_number_count'), 'company A: one employee and one Temporary have no number; the phone-only employee has one');
        $this->assertSame(1, $this->preview($token, $selections['guri B'])->json('data.missing_payment_number_count'));
        $this->assertSame(0, $this->preview($token, $selections['guri A'])->json('data.missing_payment_number_count'));
    }

    public function test_only_the_selected_record_and_month_are_counted(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $b] = $this->world();
        $this->permanent($a, 600);
        $this->permanent($b, 300);
        [, $ended] = $this->permanent($a, 400);
        $ended->update(['end_date' => '2026-09-30', 'status' => 'ended']);

        $october = $this->preview($token, ['scope' => 'company', 'client_company_id' => $a->id])->assertOk()->json('data');
        $september = $this->preview($token, ['scope' => 'company', 'client_company_id' => $a->id, 'period' => '2026-09'])->assertOk()->json('data');

        $this->assertSame([1, '600.00'], [$october['row_count'], $october['permanent_total']], 'company B\'s 300 and the ended assignment are not in October');
        $this->assertSame([2, '1000.00'], [$september['row_count'], $september['permanent_total']], 'September still has the assignment that ended on 30 September');
        $this->assertSame('2026-09', $september['period']);
    }

    public function test_an_account_number_wins_and_the_phone_is_the_fallback_and_only_both_missing_counts(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Alpha');
        $this->permanent($company, 100, '0000000001', '0000000012');   // account number (differs from the phone)
        $this->permanent($company, 100, null, '0000000013');            // phone only
        $this->permanent($company, 100, null, ' ');                      // neither

        $data = $this->preview($token, ['scope' => 'company', 'client_company_id' => $company->id])->assertOk()->json('data');

        $this->assertSame([3, 1], [$data['row_count'], $data['missing_payment_number_count']]);
    }

    // ------------------------------------------------------------------------------------------------------ privacy

    public function test_the_preview_holds_totals_only_no_names_numbers_or_ids_of_employees(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Alpha');
        [$employee, $position] = $this->permanent($company, 600, '0000000014', '0000000015');
        $temporary = $this->temporary('0000000016');
        $this->cover($position, $temporary);
        $this->permanent($this->makeCompany('Other'), 900);

        $response = $this->preview($token, ['scope' => 'company', 'client_company_id' => $company->id])->assertOk();
        $json = $response->getContent();

        foreach ([$employee->full_name, $employee->employee_number, $temporary->full_name, '0000000014', '0000000015', '0000000016', 'Other'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertEqualsCanonicalizing(
            ['period', 'scope', 'record', 'is_estimate', 'row_count', 'permanent_count', 'temporary_count', 'permanent_total', 'temporary_total', 'combined_total', 'missing_payment_number_count', 'currency'],
            array_keys($response->json('data')),
        );
    }

    // ------------------------------------------------------------------------------------------------------ errors

    public function test_a_company_or_guri_with_nothing_to_pay_is_a_clear_error(): void
    {
        [, $token] = $this->superAdminToken();
        $empty = $this->makeCompany('Empty Company');
        $emptyGuri = $this->makeCompany('Empty Guri', 'guri');
        $this->permanent($this->makeCompany('Other'));

        $company = $this->preview($token, ['scope' => 'company', 'client_company_id' => $empty->id])->assertStatus(422)->assertJsonPath('error_code', 'NO_PAYMENT_RECORDS');
        $this->assertStringContainsString("Company 'Empty Company' has no payment records for ".self::P, $company->json('message'));
        $this->preview($token, ['scope' => 'guri', 'client_company_id' => $emptyGuri->id])->assertStatus(422)->assertJsonPath('error_code', 'NO_PAYMENT_RECORDS');
    }

    public function test_invalid_selections_are_refused_like_the_download_refuses_them(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, , $ga] = $this->world();
        $this->permanent($a);
        $this->permanent($ga);

        $this->preview($token, ['scope' => 'company', 'client_company_id' => $ga->id])->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');
        $this->preview($token, ['scope' => 'guri', 'client_company_id' => $a->id])->assertStatus(422);
        $this->preview($token, ['scope' => 'company'])->assertStatus(422);
        $this->preview($token, ['scope' => 'guri', 'client_company_id' => 999999])->assertStatus(422);
        $this->preview($token, ['scope' => 'everything'])->assertStatus(422);
        $this->preview($token, ['scope' => 'all', 'period' => '2026-13'])->assertStatus(422);
        $this->preview($token, ['scope' => 'all', 'period' => 'October'])->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(self::PREVIEW)->assertStatus(422);
    }

    public function test_all_with_nothing_to_pay_reports_zero_rows_like_the_all_workbook(): void
    {
        [, $token] = $this->superAdminToken();

        $data = $this->preview($token, ['scope' => 'all'])->assertOk()->json('data');

        $this->assertSame([0, '0.00', '0.00', '0.00', 0], [$data['row_count'], $data['permanent_total'], $data['temporary_total'], $data['combined_total'], $data['missing_payment_number_count']]);
    }

    // ------------------------------------------------------------------------------------------------------ permissions

    public function test_the_preview_needs_the_same_two_permissions_as_the_download(): void
    {
        $company = $this->makeCompany('Alpha');
        $this->permanent($company);
        $query = ['scope' => 'company', 'client_company_id' => $company->id];

        $this->preview($this->tokenWithOnly(['hr.payroll.view']), $query)->assertForbidden();
        $this->preview($this->tokenWithOnly(['hr.manage']), $query)->assertForbidden();
        $this->preview($this->tokenWithOnly([]), $query)->assertForbidden();
        $this->preview($this->tokenWithOnly(['hr.payroll.view', 'hr.manage']), $query)->assertOk();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson(self::PREVIEW.'?'.http_build_query($query + ['period' => self::P]))->assertUnauthorized();
    }

    // ------------------------------------------------------------------------------------------------------ read-only

    public function test_previewing_changes_nothing_and_writes_no_audit_entry(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Alpha');
        [, $position] = $this->permanent($company);
        $this->cover($position, $this->temporary());
        $counts = fn (): array => [
            'runs' => DB::table('payroll_runs')->count(), 'items' => DB::table('payroll_items')->count(), 'covers' => DB::table('temporary_replacements')->count(),
            'cover_payments' => DB::table('temporary_replacement_payments')->count(), 'employees' => DB::table('employees')->count(), 'companies' => DB::table('client_companies')->count(),
            'assignments' => DB::table('employee_work_assignments')->count(), 'audit' => DB::table('audit_logs')->where('action', 'export')->count(),
        ];
        $before = $counts();

        $this->preview($token, ['scope' => 'company', 'client_company_id' => $company->id])->assertOk();
        $this->preview($token, ['scope' => 'all'])->assertOk();

        $this->assertSame($before, $counts());

        // The download itself still records who downloaded what (once).
        $this->book($token, ['scope' => 'company', 'client_company_id' => $company->id]);
        $this->assertSame($before['audit'] + 1, $counts()['audit']);
    }

    public function test_the_download_is_unchanged_by_the_preview_existing(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Alpha');
        $this->permanent($company);
        $empty = $this->makeCompany('Empty');

        $header = $this->book($token, ['scope' => 'company', 'client_company_id' => $company->id])->getSheetByName('Payment list')->toArray(null, true, false, false)[3];
        $this->assertSame(['Employee', 'NUMBER', 'COMPANY OR GURI', 'Type', 'Pay amount', 'Row type', 'Linked employee', 'Cover period', 'Paid to date', 'Balance'], array_slice($header, 0, 10));

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->get(self::LIST.'?'.http_build_query(['period' => self::P, 'scope' => 'company', 'client_company_id' => $empty->id]))
            ->assertStatus(422)->assertJsonPath('error_code', 'NO_PAYMENT_RECORDS');
    }
}
