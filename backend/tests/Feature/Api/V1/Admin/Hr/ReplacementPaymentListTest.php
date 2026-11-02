<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The monthly payment list and its Excel for a Replacement cycle - the 4SEASON RES October 2026 case: Mohamed (assignment #272, $140 a month) is away
 * from 12 October and returns on 14 October; one Replacement holds his position on 12 October (#305), another on 13 October (#306).
 *
 * Every Replacement is a worker of their own: named, with their OWN payment number, typed "Replacement", linked to the Permanent whose position they
 * hold, with their own cover period and amount, directly beneath that Permanent. Their pay is a payroll line (A8) - never merged into the Permanent's
 * row, never paid to the Permanent. Synthetic data only; the dollar figures are exact (October has 31 days, $140 over 31 days).
 */
class ReplacementPaymentListTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/hr/reports/monthly-payment-list';

    private const HEADERS = ['Employee', 'NUMBER', 'COMPANY OR GURI', 'Type', 'Pay amount', 'Row type', 'Linked employee', 'Cover period', 'Paid to date', 'Balance'];

    private ClientCompany $company;

    private Position $cleaner;

    private Admin $owner;

    private string $token;

    private Employee $mohamed;

    private Employee $adan;

    private Employee $abdiwahid;

    private EmployeeWorkAssignment $original;

    private EmployeeWorkAssignment $first;

    private EmployeeWorkAssignment $second;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-02 12:00:00');
        $this->company = $this->makeCompany('4SEASON RES');
        $this->cleaner = Position::query()->create(['name' => 'Cleaner']);
        [$this->owner, $this->token] = $this->superAdminToken();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function as(?string $token = null): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token);
    }

    private function person(string $name, ?string $number, string $type = 'permanent'): Employee
    {
        $employee = $this->makeEmployee('active', $type);
        $employee->forceFill(['full_name' => $name, 'payment_account_number' => $number, 'phone' => ''])->save();

        return $employee;
    }

    private function companyUrl(string $tail): string
    {
        return "/api/v1/admin/hr/client-companies/{$this->company->id}/{$tail}";
    }

    private function replace(EmployeeWorkAssignment $of, Employee $by, string $lastDay, string $from): EmployeeWorkAssignment
    {
        $id = $this->as()->postJson($this->companyUrl("employees/{$of->id}/replace"), [
            'replacement_employee_id' => $by->id, 'replacement_date' => $from, 'original_last_day' => $lastDay,
            'assignment_type' => 'replacement', 'position_id' => $this->cleaner->id,
        ])->assertOk()->json('data.replacement.id');

        return EmployeeWorkAssignment::query()->findOrFail($id);
    }

    /**
     * The production case: #272 Mohamed ($140, from 1 Oct); Adan holds the position on 12 Oct, Abdiwahid on 13 Oct; Mohamed returns on 14 Oct.
     * Both Replacements are Permanent-type employees, as in production.
     */
    private function theOctoberCase(?string $abdiwahidNumber = 'NUM-ABDIWAHID'): void
    {
        $this->mohamed = $this->person('Mohamed Said Nuur', 'NUM-MOHAMED');
        $this->adan = $this->person('Adan Isaaq Dahir', 'NUM-ADAN');
        $this->abdiwahid = $this->person('Abdiwahid Ali Gurow', $abdiwahidNumber);
        $this->original = $this->makeAssignment($this->mohamed, $this->company, null, 140, '2026-10-01');
        $this->original->update(['position_id' => $this->cleaner->id]);

        $this->first = $this->replace($this->original, $this->adan, '2026-10-11', '2026-10-12');
        $this->second = $this->replace($this->first, $this->abdiwahid, '2026-10-12', '2026-10-13');
        $this->as()->postJson($this->companyUrl("employees/{$this->second->id}/return"), ['return_date' => '2026-10-14', 'salary_amount' => 140, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'])->assertOk();
    }

    /** @param  array<string, string>  $query */
    private function book(string $period, array $query = []): Spreadsheet
    {
        $this->app['auth']->forgetGuards();
        $response = $this->withToken($this->token)->get(self::URL.'?'.http_build_query(['period' => $period] + $query))->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $this->tempFiles[] = $path;

        return IOFactory::load($path);
    }

    /** @return list<array<string, mixed>> the rows of the Payment list sheet keyed by header, down to the totals */
    private function rows(Spreadsheet $book): array
    {
        $out = [];

        foreach (array_slice($book->getSheetByName('Payment list')->toArray(null, true, false, false), 4) as $row) {
            if (($row[0] ?? null) === null || $row[0] === '' || str_starts_with((string) $row[0], 'Permanent employees total')) {
                break;
            }

            $out[] = array_combine(self::HEADERS, array_slice($row, 0, 10));
        }

        return $out;
    }

    /** @return array<string, float> */
    private function totals(Spreadsheet $book): array
    {
        $sheet = $book->getSheetByName('Payment list');
        $out = [];

        foreach ($sheet->toArray(null, true, false, false) as $index => $row) {
            if (is_string($row[0] ?? null) && (str_contains($row[0], 'total') && ! str_contains($row[0], 'have no payment number') || $row[0] === 'Combined total')) {
                $out[$row[0]] = (float) $sheet->getCell('E'.($index + 1))->getValue();
            }
        }

        return $out;
    }

    private function who(array $row): string
    {
        return ltrim(str_replace("\u{21B3}", '', (string) $row['Employee']));
    }

    private function row(array $rows, string $name): array
    {
        foreach ($rows as $row) {
            if ($this->who($row) === $name) {
                return $row;
            }
        }

        $this->fail("No row for {$name}: ".implode(', ', array_map(fn (array $r): string => $this->who($r), $rows)));
    }

    // ------------------------------------------------------------------ the October case

    public function test_each_replacement_is_a_worker_of_their_own_beneath_the_permanent_they_covered(): void
    {
        $this->theOctoberCase();
        $other = $this->person('Omar Seydali', 'NUM-OMAR');
        $this->makeAssignment($other, $this->company, null, 130, '2026-10-01');

        $rows = $this->rows($this->book('2026-10'));

        $names = array_map(fn (array $r): string => $this->who($r), $rows);
        $mohamedAt = array_search('Mohamed Said Nuur', $names, true);
        $this->assertSame(['Mohamed Said Nuur', 'Adan Isaaq Dahir', 'Abdiwahid Ali Gurow'], array_slice($names, $mohamedAt, 3), 'both Replacements sit directly beneath Mohamed, in the order they took over');
        $this->assertCount(4, $rows, 'Mohamed, his two Replacements and the unrelated employee: nobody is listed twice or dropped');

        $permanent = $this->row($rows, 'Mohamed Said Nuur');
        $this->assertSame('Permanent', $permanent['Type']);
        $this->assertSame('Permanent', $permanent['Row type']);
        $this->assertSame('NUM-MOHAMED', $permanent['NUMBER']);
        $this->assertEqualsWithDelta(130.97, $permanent['Pay amount'], 0.0001, '29 of 31 days: he was away on 12 and 13 October and is paid on the 14th');

        foreach (['Adan Isaaq Dahir' => ['NUM-ADAN', 'Oct 12', 4.52], 'Abdiwahid Ali Gurow' => ['NUM-ABDIWAHID', 'Oct 13', 4.51]] as $name => [$number, $period, $amount]) {
            $row = $this->row($rows, $name);
            $this->assertSame($number, $row['NUMBER'], "{$name} is paid on their own number, never Mohamed's");
            $this->assertNotSame($permanent['NUMBER'], $row['NUMBER']);
            $this->assertSame('Replacement', $row['Type']);
            $this->assertSame('Replacement', $row['Row type']);
            $this->assertSame('Mohamed Said Nuur', $row['Linked employee']);
            $this->assertSame($period, $row['Cover period']);
            $this->assertSame('4SEASON RES', $row['COMPANY OR GURI']);
            $this->assertEqualsWithDelta($amount, $row['Pay amount'], 0.0001, "{$name}'s own amount for their day");
            $this->assertSame('Not recorded', $row['Paid to date'], 'a draft month has recorded nothing as paid');
        }
    }

    public function test_the_position_is_paid_once_for_every_day_and_the_three_amounts_add_up_to_the_salary(): void
    {
        $this->theOctoberCase();
        $rows = $this->rows($this->book('2026-10'));

        $sum = array_sum(array_map(fn (string $n): float => (float) $this->row($rows, $n)['Pay amount'], ['Mohamed Said Nuur', 'Adan Isaaq Dahir', 'Abdiwahid Ali Gurow']));

        $this->assertEqualsWithDelta(140.00, $sum, 0.0001, 'Permanent + both Replacements = the position\'s salary: no day is paid twice and none is lost');

        $report = $this->as()->getJson(self::URL.'/preview?period=2026-10')->assertOk()->json('data');
        $this->assertSame(3, $report['row_count']);
        $this->assertSame(1, $report['permanent_count']);
        $this->assertSame(2, $report['temporary_count'], 'the two Replacement workers');
        $this->assertSame('130.97', $report['permanent_total']);
        $this->assertSame('9.03', $report['temporary_total'], 'the two Replacements together');
        $this->assertSame('140.00', $report['combined_total']);
    }

    public function test_permanent_replacement_and_combined_totals_reconcile_with_the_rows(): void
    {
        $this->theOctoberCase();
        $cover = $this->person('Temp Cover Worker', 'NUM-TEMP', 'temporary');
        $perm = $this->person('Zakariye Abdullahi', 'NUM-ZAKARIYE');
        $zakariye = $this->makeAssignment($perm, $this->company, null, 310, '2026-10-01');
        TemporaryReplacement::query()->create(['work_assignment_id' => $zakariye->id, 'replacement_employee_id' => $cover->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'currency' => 'USD', 'status' => 'ended', 'approved_at' => now()]);

        $book = $this->book('2026-10');
        $rows = $this->rows($book);
        $totals = $this->totals($book);

        $byType = fn (string $type): float => array_sum(array_map(fn (array $r): float => (float) $r['Pay amount'], array_filter($rows, fn (array $r): bool => $r['Row type'] === $type)));

        $this->assertEqualsWithDelta($byType('Permanent'), $totals['Permanent employees total'], 0.0001);
        $this->assertEqualsWithDelta($byType('Replacement'), $totals['Replacement workers total (their own rows; not in the Permanent total)'], 0.0001);
        $this->assertEqualsWithDelta($byType('Temporary'), $totals['Temporary workers total (not included in the Permanent total)'], 0.0001);
        $this->assertEqualsWithDelta(array_sum(array_map(fn (array $r): float => (float) $r['Pay amount'], $rows)), $totals['Combined total'], 0.0001, 'the combined total is every row of the list, once');
        $this->assertEqualsWithDelta(140.00 + 310.00, $totals['Combined total'], 0.0001, 'Mohamed\'s position (140) and Zakariye\'s position (310): what the two positions cost, paid once');
        $this->assertSame('Temporary', $this->row($rows, 'Temp Cover Worker')['Row type']);
        $this->assertSame('Zakariye Abdullahi', $this->row($rows, 'Temp Cover Worker')['Linked employee']);
    }

    public function test_a_missing_payment_number_is_flagged_for_the_right_worker_and_never_borrowed(): void
    {
        $this->theOctoberCase(abdiwahidNumber: null);

        $book = $this->book('2026-10');
        $rows = $this->rows($book);

        $row = $this->row($rows, 'Abdiwahid Ali Gurow');
        $this->assertSame('', (string) $row['NUMBER'], 'left blank: not Mohamed\'s number and not an id');
        $this->assertNotSame('NUM-MOHAMED', $row['NUMBER']);
        $this->assertSame('NUM-ADAN', $this->row($rows, 'Adan Isaaq Dahir')['NUMBER']);

        $sheet = $book->getSheetByName('Payment list');
        $line = array_search('Abdiwahid Ali Gurow', array_map(fn (array $r): string => $this->who($r), $rows), true) + 5;
        $this->assertSame('FFFFF2CC', $sheet->getStyle("B{$line}")->getFill()->getStartColor()->getARGB(), 'the empty NUMBER cell is highlighted');

        $detail = $book->getSheetByName('Employees')->toArray(null, true, false, false);
        $headers = $detail[3];
        $flagged = collect(array_slice($detail, 4))->filter(fn (array $r): bool => str_contains((string) ($r[1] ?? ''), 'Abdiwahid'))->map(fn (array $r): array => array_combine($headers, $r))->first();
        $this->assertNotNull($flagged);
        $this->assertStringContainsString('Missing payment number', $flagged['Data checks']);
        $this->assertSame('Replacement', $flagged['Row type']);
        $this->assertSame('MISSING', $flagged['Payment / account number']);

        $preview = $this->as()->getJson(self::URL.'/preview?period=2026-10')->assertOk()->json('data');
        $this->assertSame(1, $preview['missing_payment_number_count'], 'exactly the one worker whose number is missing');
    }

    public function test_the_employees_sheet_shows_the_replacements_with_their_cover_and_who_covered_the_permanent(): void
    {
        $this->theOctoberCase();
        $detail = $this->book('2026-10')->getSheetByName('Employees')->toArray(null, true, false, false);
        $headers = $detail[3];
        $byName = [];

        foreach (array_slice($detail, 4) as $row) {
            if (($row[1] ?? null) !== null && ($row[0] ?? '') !== 'Total') {
                $byName[$row[1]] = array_combine($headers, $row);
            }
        }

        $this->assertStringContainsString('Adan Isaaq Dahir (Replacement)', $byName['Mohamed Said Nuur']['Linked employee']);
        $this->assertStringContainsString('Abdiwahid Ali Gurow (Replacement)', $byName['Mohamed Said Nuur']['Linked employee']);
        $this->assertSame('Replacement', $byName['Adan Isaaq Dahir']['Row type']);
        $this->assertStringContainsString('Mohamed Said Nuur', $byName['Adan Isaaq Dahir']['Linked employee']);
        $this->assertSame('2026-10-12 to 2026-10-12', $byName['Adan Isaaq Dahir']['Cover period']);
        $this->assertEquals(4.52, $byName['Adan Isaaq Dahir']['Payment amount (USD)']);
        $this->assertSame('NUM-ADAN', $byName['Adan Isaaq Dahir']['Payment / account number']);
        $this->assertEquals(140.0, $byName['Adan Isaaq Dahir']['Monthly salary (USD)'], 'calculated on the covered salary');
        $this->assertSame('Not recorded', $byName['Adan Isaaq Dahir']['Paid to date (USD)']);
    }

    // ------------------------------------------------------------------ other months, other companies, other workers

    public function test_november_lists_only_mohamed_at_his_full_salary_because_the_cover_ended_in_october(): void
    {
        $this->theOctoberCase();

        $rows = $this->rows($this->book('2026-11'));

        $this->assertCount(1, $rows, 'a cover that ended in October has no November row: the Replacements are paid in October');
        $this->assertSame('Mohamed Said Nuur', $this->who($rows[0]));
        $this->assertEqualsWithDelta(140.00, $rows[0]['Pay amount'], 0.0001);
        $this->assertSame('Permanent', $rows[0]['Row type']);
    }

    public function test_a_company_list_keeps_the_replacements_of_that_company_only(): void
    {
        $this->theOctoberCase();
        $otherCompany = $this->makeCompany('Beta School');
        $teacher = $this->person('Teacher Beta', 'NUM-BETA');
        $this->makeAssignment($teacher, $otherCompany, null, 200, '2026-10-01');

        $mine = $this->rows($this->book('2026-10', ['client_company_id' => (string) $this->company->id]));
        $theirs = $this->rows($this->book('2026-10', ['client_company_id' => (string) $otherCompany->id]));

        $this->assertSame(['Mohamed Said Nuur', 'Adan Isaaq Dahir', 'Abdiwahid Ali Gurow'], array_map(fn (array $r): string => $this->who($r), $mine));
        $this->assertSame(['Teacher Beta'], array_map(fn (array $r): string => $this->who($r), $theirs), 'another company is unaffected and shows no Replacement');
    }

    public function test_a_replacement_who_also_holds_a_permanent_assignment_gets_two_rows_not_one_merged_amount(): void
    {
        $this->theOctoberCase();
        $beta = $this->makeCompany('Beta School');
        $this->makeAssignment($this->adan, $beta, null, 200, '2026-10-01');

        $rows = $this->rows($this->book('2026-10'));
        $adan = array_values(array_filter($rows, fn (array $r): bool => $this->who($r) === 'Adan Isaaq Dahir'));

        $this->assertCount(2, $adan, 'his Permanent row (Beta School) and his Replacement row (4SEASON RES) stay separate');
        $types = array_column($adan, 'Row type');
        sort($types);
        $this->assertSame(['Permanent', 'Replacement'], $types);
        $replacement = $adan[array_search('Replacement', array_column($adan, 'Row type'), true)];
        $this->assertEqualsWithDelta(4.52, $replacement['Pay amount'], 0.0001, 'only the Replacement day, not his Permanent salary');
        $this->assertSame('4SEASON RES', $replacement['COMPANY OR GURI']);
    }

    public function test_a_replacement_only_worker_is_not_reported_as_missing_from_payroll(): void
    {
        $this->theOctoberCase();

        $notInPayroll = $this->book('2026-10')->getSheetByName('Not in payroll')->toArray(null, true, false, false);
        $text = json_encode($notInPayroll);

        $this->assertStringNotContainsString('Adan Isaaq Dahir', $text);
        $this->assertStringNotContainsString('Abdiwahid Ali Gurow', $text);
    }

    public function test_a_company_without_any_replacement_keeps_the_plain_layout_and_zero_replacement_total(): void
    {
        $perm = $this->person('Only Permanent', 'NUM-ONLY');
        $this->makeAssignment($perm, $this->company, null, 310, '2026-10-01');

        $book = $this->book('2026-10');
        $rows = $this->rows($book);
        $totals = $this->totals($book);

        $this->assertCount(1, $rows);
        $this->assertSame('Permanent', $rows[0]['Row type']);
        $this->assertEqualsWithDelta(0.0, $totals['Replacement workers total (their own rows; not in the Permanent total)'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $totals['Temporary workers total (not included in the Permanent total)'], 0.0001);
        $this->assertEqualsWithDelta(310.0, $totals['Combined total'], 0.0001);
        $this->assertEqualsWithDelta(310.0, $totals['Permanent employees total'], 0.0001);
    }

    public function test_a_replacement_that_records_nobody_is_still_listed_and_flagged(): void
    {
        $this->theOctoberCase();
        $this->second->update(['replaces_assignment_id' => null]);
        $this->first->update(['replaces_assignment_id' => null]);

        $rows = $this->rows($this->book('2026-10'));
        $row = $this->row($rows, 'Adan Isaaq Dahir');

        $this->assertSame('Not recorded', $row['Linked employee'], 'said plainly, not guessed');
        $this->assertSame('NUM-ADAN', $row['NUMBER']);
        $this->assertEqualsWithDelta(4.52, $row['Pay amount'], 0.0001, 'the worker is still paid');
    }

    // ------------------------------------------------------------------ no duplicate pay

    public function test_a_second_replacement_cannot_take_a_day_another_replacement_already_holds(): void
    {
        $this->theOctoberCase();
        $stranger = $this->person('Stranger Worker', 'NUM-STRANGER');

        $response = $this->as()->postJson($this->companyUrl("employees/{$this->original->id}/replace"), [
            'replacement_employee_id' => $stranger->id, 'replacement_date' => '2026-10-12', 'original_last_day' => '2026-10-11',
            'assignment_type' => 'replacement', 'position_id' => $this->cleaner->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(3, EmployeeWorkAssignment::query()->where('client_company_id', $this->company->id)->count(), 'nothing was created');
        $this->assertCount(3, $this->rows($this->book('2026-10')), 'the list is unchanged: nobody is paid twice for 12 October');
    }

    public function test_a_finalized_month_records_each_replacement_as_paid_or_not_paid_and_the_balance(): void
    {
        $this->theOctoberCase();
        $this->as()->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertSuccessful();
        $run = PayrollRun::query()->where('period', '2026-10')->firstOrFail();
        $run->items()->where('work_assignment_id', $this->first->id)->update(['payment_status' => 'paid']);
        $run->forceFill(['status' => PayrollRun::STATUS_FINALIZED])->save();

        $rows = $this->rows($this->book('2026-10'));

        $adan = $this->row($rows, 'Adan Isaaq Dahir');
        $abdiwahid = $this->row($rows, 'Abdiwahid Ali Gurow');
        $this->assertEqualsWithDelta(4.52, $adan['Paid to date'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $adan['Balance'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $abdiwahid['Paid to date'], 0.0001);
        $this->assertEqualsWithDelta(4.51, $abdiwahid['Balance'], 0.0001, 'what is still owed to the second Replacement');
    }
}
