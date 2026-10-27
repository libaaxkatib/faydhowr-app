<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
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
 * The "Payment list" sheet of the monthly Excel (owner decision 2026-10-27): exactly ten columns - Employee, NUMBER, COMPANY OR GURI, Type, Pay amount,
 * Row type, Linked employee, Cover period, Paid to date, Balance - in every filter mode; NUMBER is the payment destination number, COMPANY OR GURI is
 * the stored company relationship, a Temporary sits directly beneath the Permanent they covered, and the Permanent and Temporary totals are separate.
 * The download can be limited to all records, ONE Company or ONE Guri, chosen from the records that really exist. Synthetic data only.
 */
class MonthlyPaymentListTenColumnTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/hr/reports/monthly-payment-list';

    private const P = '2026-10';

    private const HEADERS = ['Employee', 'NUMBER', 'COMPANY OR GURI', 'Type', 'Pay amount', 'Row type', 'Linked employee', 'Cover period', 'Paid to date', 'Balance'];

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
    private function permanent(ClientCompany $company, int $dollars = 600, ?string $number = 'auto', ?string $name = null): array
    {
        $employee = $this->makeEmployee('active', 'permanent');
        $employee->forceFill(['payment_account_number' => $number === 'auto' ? 'P'.fake()->unique()->numerify('#######') : $number, 'full_name' => $name ?? $employee->full_name])->save();

        return [$employee, $this->makeAssignment($employee, $company, null, $dollars, '2026-01-01')];
    }

    private function temporary(?string $number = 'auto'): Employee
    {
        $employee = $this->makeEmployee('active', 'temporary');
        $employee->forceFill(['payment_account_number' => $number === 'auto' ? 'T'.fake()->unique()->numerify('#######') : $number])->save();

        return $employee;
    }

    private function cover(EmployeeWorkAssignment $position, Employee $temporary, string $start = '2026-10-01', ?string $end = '2026-10-10', bool $approved = true): TemporaryReplacement
    {
        return TemporaryReplacement::query()->create([
            'work_assignment_id' => $position->id, 'replacement_employee_id' => $temporary->id, 'start_date' => $start, 'end_date' => $end,
            'currency' => 'USD', 'status' => $end === null ? 'active' : 'ended', 'approved_at' => $approved ? now() : null,
        ]);
    }

    private function request(string $token, array $query)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->get(self::URL.'?'.http_build_query($query + ['period' => self::P]));
    }

    private function book(string $token, array $query = []): Spreadsheet
    {
        $response = $this->request($token, $query)->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->tempFiles[] = $path;

        return IOFactory::load($path);
    }

    /** @return list<array<string, mixed>> the rows of the Payment list sheet keyed by header, stopping at the totals */
    private function rows(Spreadsheet $book): array
    {
        $table = $book->getSheetByName('Payment list')->toArray(null, true, false, false);
        $out = [];

        foreach (array_slice($table, 4) as $row) {
            if (($row[0] ?? null) === null || $row[0] === '' || str_starts_with((string) $row[0], 'Permanent employees total') || $row[0] === 'No payments for this month.') {
                break;
            }

            $out[] = array_combine(self::HEADERS, array_slice($row, 0, 10));
        }

        return $out;
    }

    /** @return array<string, float> the totals written under the table, by label */
    private function totals(Spreadsheet $book): array
    {
        $sheet = $book->getSheetByName('Payment list');
        $out = [];

        foreach ($sheet->toArray(null, true, false, false) as $index => $row) {
            if (is_string($row[0] ?? null) && (str_contains($row[0], 'total') || $row[0] === 'Combined total')) {
                $out[$row[0]] = (float) $sheet->getCell('E'.($index + 1))->getValue();
            }
        }

        return $out;
    }

    private function names(array $rows): array
    {
        return array_map(fn (array $r): string => ltrim(str_replace("\u{21B3}", '', (string) $r['Employee'])), $rows);
    }

    // ------------------------------------------------------------------------------------------------------ the ten columns

    public function test_the_payment_list_has_exactly_the_ten_approved_columns_in_order_in_every_mode(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Company One');
        $guri = $this->makeCompany('Guri One', 'guri');
        $this->permanent($company);
        $this->permanent($guri);

        foreach ([[], ['scope' => 'all'], ['scope' => 'company', 'client_company_id' => $company->id], ['scope' => 'guri', 'client_company_id' => $guri->id]] as $query) {
            $sheet = $this->book($token, $query)->getSheetByName('Payment list');
            $header = $sheet->toArray(null, true, false, false)[3];

            $this->assertSame(self::HEADERS, array_slice($header, 0, 10));
            $this->assertCount(10, array_filter($header, fn ($v): bool => $v !== null && $v !== ''), 'no eleventh column');
            $this->assertSame('A4:J'.(count($this->rows($this->book($token, $query))) + 4), $sheet->getAutoFilter()->getRange());
        }
    }

    public function test_the_worked_example_600_with_a_10_day_october_cover_gives_406_45_193_55_and_600_00(): void
    {
        [, $token] = $this->superAdminToken();
        $companyB = $this->makeCompany('Example Company B');
        [$ahmed, $position] = $this->permanent($companyB, 600, 'TEST-PERM-0001', 'Ahmed Ali (example)');
        $hassan = $this->temporary('TEST-TEMP-0002');
        $hassan->forceFill(['full_name' => 'Hassan Omar (example)'])->save();
        $this->cover($position, $hassan, '2026-10-01', '2026-10-10');

        $rows = $this->rows($this->book($token));

        $this->assertCount(2, $rows);
        $this->assertSame(['Ahmed Ali (example)', 'TEST-PERM-0001', 'Example Company B', 'Permanent', 'Permanent', "\u{2014}", "\u{2014}"], [$rows[0]['Employee'], $rows[0]['NUMBER'], $rows[0]['COMPANY OR GURI'], $rows[0]['Type'], $rows[0]['Row type'], $rows[0]['Linked employee'], $rows[0]['Cover period']]);
        $this->assertEqualsWithDelta(406.45, (float) $rows[0]['Pay amount'], 0.0001);
        $this->assertSame(["\u{21B3} Hassan Omar (example)", 'TEST-TEMP-0002', 'Example Company B', 'Temporary', 'Temporary', 'Ahmed Ali (example)', "Oct 1\u{2013}10"], [$rows[1]['Employee'], $rows[1]['NUMBER'], $rows[1]['COMPANY OR GURI'], $rows[1]['Type'], $rows[1]['Row type'], $rows[1]['Linked employee'], $rows[1]['Cover period']]);
        $this->assertEqualsWithDelta(193.55, (float) $rows[1]['Pay amount'], 0.0001);
        $this->assertEqualsWithDelta(193.55, (float) $rows[1]['Balance'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $rows[1]['Paid to date'], 0.0001);

        $totals = $this->totals($this->book($token));
        $this->assertEqualsWithDelta(406.45, $totals['Permanent employees total'], 0.0001);
        $this->assertEqualsWithDelta(193.55, $totals['Temporary workers total (not included in the Permanent total)'], 0.0001);
        $this->assertEqualsWithDelta(600.00, $totals['Combined total'], 0.0001);
        $this->assertEqualsWithDelta(600.00, array_sum(array_map(fn (array $r): float => (float) $r['Pay amount'], $rows)), 0.0001, 'the column adds up to the covered salary: nothing twice');
    }

    public function test_paid_to_date_and_balance_follow_what_is_recorded(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->permanent($this->makeCompany('Alpha'), 600);
        $temporary = $this->temporary();
        $cover = $this->cover($position, $temporary);
        TemporaryReplacementPayment::query()->create(['temporary_replacement_id' => $cover->id, 'payment_date' => '2026-10-20', 'amount' => '100.00', 'paid_by' => Admin::factory()->create()->id]);

        // Before the payroll month is finalized nothing is recorded as paid for the Permanent line: it says so instead of claiming "0".
        $rows = $this->rows($this->book($token));
        $this->assertSame(['Not recorded', 'Not recorded'], [$rows[0]['Paid to date'], $rows[0]['Balance']]);
        $this->assertEqualsWithDelta(100.00, (float) $rows[1]['Paid to date'], 0.0001);
        $this->assertEqualsWithDelta(93.55, (float) $rows[1]['Balance'], 0.0001);

        // Finalized and marked paid: the Permanent line is paid in full.
        $run = $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => self::P])->assertCreated()->json('data.id');
        foreach (['review', 'approve', 'finalize'] as $step) {
            $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run}/{$step}")->assertOk();
        }
        $item = PayrollItem::query()->where('payroll_run_id', $run)->firstOrFail();
        $this->withToken($token)->postJson("/api/v1/admin/hr/payroll-items/{$item->id}/pay", ['payment_date' => '2026-11-02'])->assertOk();

        $rows = $this->rows($this->book($token));
        $this->assertEqualsWithDelta(406.45, (float) $rows[0]['Paid to date'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $rows[0]['Balance'], 0.0001);
        $this->assertEqualsWithDelta(93.55, (float) $rows[1]['Balance'], 0.0001, 'the Temporary balance is separate');
    }

    // ------------------------------------------------------------------------------------------------------ NUMBER

    public function test_number_is_the_payment_destination_number_never_the_employee_id(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Alpha');
        [$withAccount] = $this->permanent($company, 600, '0000000001');
        [$withPhoneOnly, $phoneAssignment] = $this->permanent($company, 500, null);
        $withPhoneOnly->forceFill(['phone' => '0000000002'])->save();
        $temp = $this->temporary('0000000003');
        $this->cover($phoneAssignment, $temp);

        $rows = collect($this->rows($this->book($token)))->keyBy(fn (array $r): string => $this->names([$r])[0]);

        $this->assertSame('0000000001', $rows[$withAccount->full_name]['NUMBER'], 'the recorded payment/account number');
        $this->assertSame('0000000002', $rows[$withPhoneOnly->full_name]['NUMBER'], 'no account number recorded: the registration phone is the payment number (existing owner rule)');
        $this->assertSame('0000000003', $rows[$temp->full_name]['NUMBER'], 'a Temporary row uses the same field');
        foreach ([$withAccount, $withPhoneOnly, $temp] as $employee) {
            $this->assertNotSame($employee->employee_number, $rows[$employee->full_name]['NUMBER']);
            $this->assertNotSame((string) $employee->id, $rows[$employee->full_name]['NUMBER']);
        }
    }

    public function test_a_missing_number_stays_blank_is_highlighted_and_reported(): void
    {
        [, $token] = $this->superAdminToken();
        [$noNumber, $position] = $this->permanent($this->makeCompany('Alpha'), 600, null);
        // Blank (whitespace only) phones: PaymentNumber treats both as missing, and they stay distinct for the unique phone column.
        $noNumber->forceFill(['phone' => ' '])->save();
        $temp = $this->temporary(null);
        $temp->forceFill(['phone' => '  '])->save();
        $this->cover($position, $temp);

        $book = $this->book($token);
        $sheet = $book->getSheetByName('Payment list');
        $rows = $this->rows($book);

        $this->assertSame(['', ''], [(string) $rows[0]['NUMBER'], (string) $rows[1]['NUMBER']], 'blank - no employee id, no invented number');
        $this->assertSame('FFF2CC', $sheet->getStyle('B5')->getFill()->getStartColor()->getRGB());
        $this->assertSame('FFF2CC', $sheet->getStyle('B6')->getFill()->getStartColor()->getRGB());
        $flat = json_encode($sheet->toArray(null, true, false, false));
        $this->assertStringContainsString('2 row(s) have no payment number', $flat);
        // ... and the detail sheet carries the follow-up flag for HR.
        $detail = json_encode($book->getSheetByName('Employees')->toArray(null, true, false, false));
        $this->assertStringContainsString('Missing payment number', $detail);
    }

    // ------------------------------------------------------------------------------------------------------ COMPANY OR GURI

    public function test_company_or_guri_is_the_stored_relationship_of_the_covered_position(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Stored Company');
        $guri = $this->makeCompany('Stored Guri', 'guri');
        $other = $this->makeCompany('Temporary Own Company');
        [, $inCompany] = $this->permanent($company);
        [, $inGuri] = $this->permanent($guri);
        $temp = $this->temporary();
        // The Temporary also has an assignment of their own at another company: it does NOT decide where this cover is shown.
        $this->makeAssignment($temp, $other, null, 200, '2026-01-01');
        $this->cover($inGuri, $temp);

        $rows = collect($this->rows($this->book($token)));

        $this->assertEqualsCanonicalizing(['Stored Company', 'Stored Guri', 'Stored Guri'], $rows->pluck('COMPANY OR GURI')->all());
        $this->assertSame('Stored Guri', $rows->firstWhere('Row type', 'Temporary')['COMPANY OR GURI']);
        $this->assertNotContains('Temporary Own Company', $rows->pluck('COMPANY OR GURI')->all());
        $this->assertNotNull($inCompany);
    }

    // ------------------------------------------------------------------------------------------------------ the selectors

    public function test_the_filter_options_are_the_companies_and_guryo_that_really_exist(): void
    {
        [, $token] = $this->superAdminToken();
        $zeta = $this->makeCompany('Zeta '.fake()->unique()->word());
        $alpha = $this->makeCompany('Alpha '.fake()->unique()->word());
        $guriB = $this->makeCompany('Guri Bravo '.fake()->unique()->word(), 'guri');
        $guriA = $this->makeCompany('Guri Alpha '.fake()->unique()->word(), 'guri');

        $this->app['auth']->forgetGuards();
        $data = $this->withToken($token)->getJson(self::URL.'/filters')->assertOk()->json('data');

        $this->assertSame([$alpha->id, $zeta->id], array_column($data['companies'], 'id'));
        $this->assertSame([$alpha->name, $zeta->name], array_column($data['companies'], 'name'));
        $this->assertSame([$guriA->id, $guriB->id], array_column($data['guryo'], 'id'));
        $this->assertSame(4, count($data['companies']) + count($data['guryo']), 'nothing else is listed');

        // A record added later appears; nothing is hard-coded or created by the call.
        $before = ClientCompany::query()->count();
        $new = $this->makeCompany('Brand New '.fake()->unique()->word(), 'guri');
        $this->app['auth']->forgetGuards();
        $again = $this->withToken($token)->getJson(self::URL.'/filters')->assertOk()->json('data');
        $this->assertContains($new->id, array_column($again['guryo'], 'id'));
        $this->assertSame($before + 1, ClientCompany::query()->count());
    }

    public function test_with_no_company_or_guri_the_options_are_empty(): void
    {
        [, $token] = $this->superAdminToken();

        $data = $this->withToken($token)->getJson(self::URL.'/filters')->assertOk()->json('data');

        $this->assertSame(['companies' => [], 'guryo' => []], $data);
    }

    public function test_the_filter_options_need_the_same_permissions_as_the_download(): void
    {
        $url = self::URL.'/filters';
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenWithOnly(['hr.payroll.view']))->getJson($url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenWithOnly(['hr.manage']))->getJson($url)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->tokenWithOnly(['hr.payroll.view', 'hr.manage']))->getJson($url)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson($url)->assertUnauthorized();
    }

    // ------------------------------------------------------------------------------------------------------ the filters

    /** @return array{0: ClientCompany, 1: ClientCompany, 2: ClientCompany, 3: ClientCompany} Company A, Company B, Guri A, Guri B each with one Permanent */
    private function world(): array
    {
        $a = $this->makeCompany('Company A '.fake()->unique()->word());
        $b = $this->makeCompany('Company B '.fake()->unique()->word());
        $ga = $this->makeCompany('Guri A '.fake()->unique()->word(), 'guri');
        $gb = $this->makeCompany('Guri B '.fake()->unique()->word(), 'guri');

        return [$a, $b, $ga, $gb];
    }

    public function test_all_one_company_and_one_guri_each_export_only_their_own_records(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $b, $ga, $gb] = $this->world();
        $people = [];
        foreach (['a' => $a, 'b' => $b, 'ga' => $ga, 'gb' => $gb] as $key => $company) {
            [$employee, $position] = $this->permanent($company, 600);
            $people[$key] = [$employee, $position];
        }
        $tempB = $this->temporary();
        $this->cover($people['b'][1], $tempB);

        $all = $this->rows($this->book($token));
        $this->assertCount(5, $all, 'four Permanent employees and one Temporary');

        $onlyB = $this->rows($this->book($token, ['scope' => 'company', 'client_company_id' => $b->id]));
        $this->assertSame([$people['b'][0]->full_name, $tempB->full_name], $this->names($onlyB));
        $this->assertSame([$b->name], array_values(array_unique(array_column($onlyB, 'COMPANY OR GURI'))));

        $onlyGuri = $this->rows($this->book($token, ['scope' => 'guri', 'client_company_id' => $gb->id]));
        $this->assertSame([$people['gb'][0]->full_name], $this->names($onlyGuri));
        $this->assertSame([$gb->name], array_column($onlyGuri, 'COMPANY OR GURI'));

        $onlyA = $this->rows($this->book($token, ['scope' => 'company', 'client_company_id' => $a->id]));
        $this->assertSame([$people['a'][0]->full_name], $this->names($onlyA));
        foreach ([$onlyA, $onlyB, $onlyGuri] as $filtered) {
            $this->assertNotContains($people['ga'][0]->full_name, $this->names($filtered));
        }
    }

    public function test_the_filter_changes_the_downloaded_file_and_its_totals(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $b] = $this->world();
        [, $positionA] = $this->permanent($a, 600);
        [, $positionB] = $this->permanent($b, 300);
        $this->cover($positionA, $this->temporary());

        $all = $this->totals($this->book($token));
        $onlyA = $this->totals($this->book($token, ['scope' => 'company', 'client_company_id' => $a->id]));
        $onlyB = $this->totals($this->book($token, ['scope' => 'company', 'client_company_id' => $b->id]));

        $this->assertEqualsWithDelta(900.00, $all['Combined total'], 0.0001);
        $this->assertEqualsWithDelta(600.00, $onlyA['Combined total'], 0.0001);
        $this->assertEqualsWithDelta(193.55, $onlyA['Temporary workers total (not included in the Permanent total)'], 0.0001);
        $this->assertEqualsWithDelta(300.00, $onlyB['Combined total'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $onlyB['Temporary workers total (not included in the Permanent total)'], 0.0001);
        $this->assertNotNull($positionB);
        // The summary sheet says which record the file is about.
        $summary = $this->book($token, ['scope' => 'company', 'client_company_id' => $a->id])->getSheetByName('Summary');
        $this->assertStringContainsString('Company: '.$a->name, (string) $summary->getCell('A1')->getValue().(string) $summary->getCell('A2')->getValue());
    }

    public function test_the_reporting_month_is_respected(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Alpha');
        [$ended, $endedAssignment] = $this->permanent($company, 600);
        $endedAssignment->update(['end_date' => '2026-09-30', 'status' => 'ended']);
        [$current] = $this->permanent($company, 600);

        $october = $this->names($this->rows($this->book($token, ['scope' => 'company', 'client_company_id' => $company->id])));
        $september = $this->names($this->rows($this->book($token, ['scope' => 'company', 'client_company_id' => $company->id, 'period' => '2026-09'])));

        $this->assertSame([$current->full_name], $october);
        $this->assertEqualsCanonicalizing([$current->full_name, $ended->full_name], $september);
    }

    public function test_cross_company_covers_stay_with_the_company_of_the_covered_position(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $b] = $this->world();
        // One Permanent employee holds a position at A and another at B; each is covered by a different Temporary.
        $person = $this->makeEmployee('active', 'permanent');
        $person->forceFill(['payment_account_number' => '0000000004'])->save();
        $atA = $this->makeAssignment($person, $a, null, 600, '2026-01-01');
        $atB = $this->makeAssignment($person, $b, null, 300, '2026-01-01');
        $tempA = $this->temporary();
        $tempB = $this->temporary();
        $this->cover($atA, $tempA, '2026-10-01', '2026-10-10');
        $this->cover($atB, $tempB, '2026-10-11', '2026-10-20');

        // All: one row for the person (their lines are added), each Temporary directly beneath, in order.
        $all = $this->rows($this->book($token));
        $this->assertSame([$person->full_name, $tempA->full_name, $tempB->full_name], $this->names($all));
        $this->assertSame([$person->full_name, $person->full_name], [$all[1]['Linked employee'], $all[2]['Linked employee']]);
        $this->assertEqualsWithDelta(193.55, (float) $all[1]['Pay amount'], 0.0001);
        $this->assertEqualsWithDelta(300 * 10 / 31, (float) $all[2]['Pay amount'], 0.01);

        // One company: only that company's line of the person and only the covers of that company's position.
        $onlyA = $this->rows($this->book($token, ['scope' => 'company', 'client_company_id' => $a->id]));
        $this->assertSame([$person->full_name, $tempA->full_name], $this->names($onlyA));
        $this->assertEqualsWithDelta(406.45, (float) $onlyA[0]['Pay amount'], 0.0001, 'only the A line: B\'s salary is not added');
        $onlyB = $this->rows($this->book($token, ['scope' => 'company', 'client_company_id' => $b->id]));
        $this->assertSame([$person->full_name, $tempB->full_name], $this->names($onlyB));
        $this->assertSame($b->name, $onlyB[1]['COMPANY OR GURI']);
    }

    public function test_the_same_temporary_on_two_companies_appears_under_each_filter_only_with_that_companys_cover(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, $b] = $this->world();
        [, $positionA] = $this->permanent($a, 600);
        [, $positionB] = $this->permanent($b, 600);
        $shared = $this->temporary();
        $this->cover($positionA, $shared, '2026-10-01', '2026-10-10');
        $this->cover($positionB, $shared, '2026-10-11', '2026-10-20');

        foreach ([$a, $b] as $company) {
            $rows = $this->rows($this->book($token, ['scope' => 'company', 'client_company_id' => $company->id]));
            $temporaryRows = array_values(array_filter($rows, fn (array $r): bool => $r['Row type'] === 'Temporary'));
            $this->assertCount(1, $temporaryRows);
            $this->assertSame($company->name, $temporaryRows[0]['COMPANY OR GURI']);
        }

        $this->assertCount(2, array_filter($this->rows($this->book($token)), fn (array $r): bool => $r['Row type'] === 'Temporary'));
    }

    // ------------------------------------------------------------------------------------------------------ validation and empty results

    public function test_the_backend_validates_the_scope_and_the_record(): void
    {
        [, $token] = $this->superAdminToken();
        [$a, , $ga] = $this->world();
        $this->permanent($a);
        $this->permanent($ga);

        // A Guri sent as a Company (and the reverse) is refused, never silently accepted.
        $this->request($token, ['scope' => 'company', 'client_company_id' => $ga->id])->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');
        $this->request($token, ['scope' => 'guri', 'client_company_id' => $a->id])->assertStatus(422);
        // A scoped request must name a record that exists.
        $this->request($token, ['scope' => 'company'])->assertStatus(422);
        $this->request($token, ['scope' => 'guri', 'client_company_id' => 999999])->assertStatus(422);
        $this->request($token, ['scope' => 'company', 'client_company_id' => 'abc'])->assertStatus(422);
        $this->request($token, ['scope' => 'everything'])->assertStatus(422);
        // A name sent by the client is ignored: only a validated id selects a record.
        $names = $this->names($this->rows($this->book($token, ['company_name' => $a->name, 'client_company_name' => 'Company A', 'scope' => 'all'])));
        $this->assertCount(2, $names);
        // scope=all ignores an id; an id without a scope keeps the earlier one-record meaning.
        $this->assertCount(2, $this->rows($this->book($token, ['scope' => 'all', 'client_company_id' => $a->id])));
        $this->assertCount(1, $this->rows($this->book($token, ['client_company_id' => $a->id])));
    }

    public function test_the_filtered_download_needs_the_same_permissions(): void
    {
        $company = $this->makeCompany('Alpha');
        $this->permanent($company);
        $query = ['scope' => 'company', 'client_company_id' => $company->id];

        $this->request($this->tokenWithOnly(['hr.payroll.view']), $query)->assertForbidden();
        $this->request($this->tokenWithOnly(['hr.manage']), $query)->assertForbidden();
        $this->request($this->tokenWithOnly(['hr.payroll.view', 'hr.manage']), $query)->assertOk();
    }

    public function test_a_company_or_guri_with_nothing_to_pay_says_so_and_produces_no_file(): void
    {
        [, $token] = $this->superAdminToken();
        $empty = $this->makeCompany('Empty Company');
        $emptyGuri = $this->makeCompany('Empty Guri', 'guri');
        $this->permanent($this->makeCompany('Other'));

        $company = $this->request($token, ['scope' => 'company', 'client_company_id' => $empty->id])->assertStatus(422)->assertJsonPath('error_code', 'NO_PAYMENT_RECORDS');
        $this->assertStringContainsString("Company 'Empty Company' has no payment records for ".self::P, $company->json('message'));
        $guri = $this->request($token, ['scope' => 'guri', 'client_company_id' => $emptyGuri->id])->assertStatus(422)->assertJsonPath('error_code', 'NO_PAYMENT_RECORDS');
        $this->assertStringContainsString("Guri 'Empty Guri'", $guri->json('message'));
    }

    public function test_downloading_changes_nothing(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Alpha');
        [, $position] = $this->permanent($company);
        $this->cover($position, $this->temporary());
        $counts = fn (): array => [
            PayrollRun::query()->count(), PayrollItem::query()->count(), TemporaryReplacementPayment::query()->count(), TemporaryReplacement::query()->count(), Employee::query()->count(),
        ];
        $before = $counts();

        $this->book($token, ['scope' => 'company', 'client_company_id' => $company->id]);
        $this->book($token);

        $this->assertSame($before, $counts());
    }

    public function test_the_detail_sheet_keeps_every_earlier_column(): void
    {
        [, $token] = $this->superAdminToken();
        $this->permanent($this->makeCompany('Alpha'));

        $header = $this->book($token)->getSheetByName('Employees')->toArray(null, true, false, false)[3];

        $this->assertSame([
            'Employee number', 'Employee name', 'Payment / account number', 'Payment number source', 'Company / Guri', 'Company category', 'Monthly salary (USD)',
            'Days paid', 'Days in month', 'Gross pay (USD)', 'Deductions (USD)', 'Adjustments (USD)', 'Payment amount (USD)', 'Payment status', 'Data checks', 'Internal employee ID',
            'Row type', 'Linked employee', 'Cover period', 'Paid to date (USD)', 'Balance (USD)',
        ], array_slice($header, 0, 21), 'nothing was removed, renamed or repurposed; the ten-column sheet is an addition');
    }
}
