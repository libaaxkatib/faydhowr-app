<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Support\Hr\Payroll\MonthlyPaymentReportExporter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The full workbook preview shown before the Excel download: every worksheet, column, row, value and total of the file, and the guarantee that the
 * download is exactly what was previewed. The preview is read back from the workbook the download's own writer produces, so these tests compare it
 * cell by cell with the downloaded file for All, one Company and one Guri, and check that a download whose data changed after the preview is refused.
 * Synthetic data only.
 */
class MonthlyPaymentWorkbookPreviewTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const LIST = '/api/v1/admin/hr/reports/monthly-payment-list';

    private const PREVIEW = '/api/v1/admin/hr/reports/monthly-payment-list/workbook-preview';

    private const P = '2026-10';

    /** @var list<string> */
    private array $tempFiles = [];

    private string $token;

    private ClientCompany $a;

    private ClientCompany $b;

    private ClientCompany $guri;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-12-15 10:00:00');
        [, $this->token] = $this->superAdminToken();

        $this->a = $this->makeCompany('Alpha Co');
        $this->b = $this->makeCompany('Bravo Co');
        $this->guri = $this->makeCompany('Gury One', 'guri');

        [, $positionA] = $this->permanent($this->a, 600);
        $this->permanent($this->a, 450, '=1+1');          // text that looks like a formula stays text
        $this->permanent($this->a, 250, null);            // no payment number at all: highlighted, blank
        $this->permanent($this->b, 300);
        $this->permanent($this->guri, 500);

        $temporary = $this->makeEmployee('active', 'temporary');
        $temporary->forceFill(['payment_account_number' => 'T1234567'])->save();
        TemporaryReplacement::query()->create([
            'work_assignment_id' => $positionA->id, 'replacement_employee_id' => $temporary->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-10',
            'currency' => 'USD', 'status' => 'ended', 'approved_at' => now(),
        ]);
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
    private function permanent(ClientCompany $company, int $dollars, ?string $number = 'auto'): array
    {
        $employee = $this->makeEmployee('active', 'permanent');
        $employee->forceFill([
            'payment_account_number' => $number === 'auto' ? 'P'.fake()->unique()->numerify('#######') : $number,
            'phone' => $number === null ? '  ' : $employee->phone,
        ])->save();

        return [$employee, $this->makeAssignment($employee, $company, null, $dollars, '2026-01-01')];
    }

    private function preview(array $query = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->getJson(self::PREVIEW.'?'.http_build_query($query + ['period' => self::P]));
    }

    private function download(array $query = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->get(self::LIST.'?'.http_build_query($query + ['period' => self::P]));
    }

    private function book($response): Spreadsheet
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $copy = tempnam(sys_get_temp_dir(), 'wbp').'.xlsx';
        copy($path, $copy);
        $this->tempFiles[] = $copy;

        return IOFactory::load($copy);
    }

    /** @return list<array<string, mixed>> */
    private function selections(): array
    {
        return [
            ['label' => 'all', 'query' => ['scope' => 'all']],
            ['label' => 'company', 'query' => ['scope' => 'company', 'client_company_id' => $this->a->id]],
            ['label' => 'other company', 'query' => ['scope' => 'company', 'client_company_id' => $this->b->id]],
            ['label' => 'guri', 'query' => ['scope' => 'guri', 'client_company_id' => $this->guri->id]],
        ];
    }

    public function test_the_preview_has_the_four_sheets_with_the_approved_columns(): void
    {
        $data = $this->preview(['scope' => 'all'])->assertOk()->json('data');
        $sheets = collect($data['sheets'])->keyBy('name');

        $this->assertSame(['Summary', 'Payment list', 'Employees', 'Not in payroll'], array_column($data['sheets'], 'name'));
        $this->assertSame(MonthlyPaymentReportExporter::PAYMENT_LIST_HEADERS, $sheets['Payment list']['headers']);
        $this->assertCount(10, $sheets['Payment list']['headers']);
        $this->assertSame(MonthlyPaymentReportExporter::DETAIL_HEADERS, $sheets['Employees']['headers']);
        $this->assertCount(21, $sheets['Employees']['headers']);
        $this->assertSame(['Employee number', 'Employee name', 'Status', 'Data check', 'Reason'], $sheets['Not in payroll']['headers']);
        $this->assertSame(4, $sheets['Payment list']['header_row']);
        $this->assertSame('A5', $sheets['Payment list']['frozen_at']);
        $this->assertSame('D5', $sheets['Employees']['frozen_at']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $data['content_hash']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2} UTC$/', $data['generated_at']);

        // The same totals the earlier preview gives are part of the answer.
        $this->assertSame('USD', $data['currency']);
        $this->assertSame(6, $data['row_count']);
        $this->assertEqualsWithDelta((float) $data['permanent_total'] + (float) $data['temporary_total'], (float) $data['combined_total'], 0.0001);
    }

    public function test_every_cell_of_the_preview_is_the_cell_of_the_downloaded_workbook_for_all_a_company_and_a_guri(): void
    {
        foreach ($this->selections() as ['label' => $label, 'query' => $query]) {
            $data = $this->preview($query)->assertOk()->json('data');
            $book = $this->book($this->download($query + ['preview_hash' => $data['content_hash'], 'generated_at' => $data['generated_at']])->assertOk());

            $this->assertSame(array_column($data['sheets'], 'name'), $book->getSheetNames(), "{$label}: the same worksheets in the same order");

            foreach ($data['sheets'] as $index => $sheet) {
                $ws = $book->getSheet($index);
                $this->assertSame($ws->getHighestDataRow(), $sheet['row_count'], "{$label} / {$sheet['name']}: rows");
                $this->assertSame(Coordinate::columnIndexFromString($ws->getHighestDataColumn()), $sheet['column_count'], "{$label} / {$sheet['name']}: columns");

                for ($r = 1; $r <= $sheet['row_count']; $r++) {
                    for ($c = 1; $c <= $sheet['column_count']; $c++) {
                        $coordinate = Coordinate::stringFromColumnIndex($c).$r;
                        $expected = $ws->cellExists($coordinate) ? (string) $ws->getCell($coordinate)->getFormattedValue() : '';
                        $cell = $sheet['rows'][$r - 1][$c - 1] ?? null;
                        $shown = $cell === null ? '' : $cell[0];
                        $this->assertSame($expected, $shown, "{$label} / {$sheet['name']} / {$coordinate}");

                        if ($expected !== '') {
                            $fill = $ws->getStyle($coordinate)->getFill()->getFillType() === 'solid' ? $ws->getStyle($coordinate)->getFill()->getStartColor()->getRGB() : null;
                            $this->assertSame($fill, $sheet['styles'][$cell[1]]['fill'] ?? null, "{$label} / {$sheet['name']} / {$coordinate}: highlight");
                            $this->assertSame((bool) $ws->getStyle($coordinate)->getFont()->getBold(), $sheet['styles'][$cell[1]]['bold'] ?? false, "{$label} / {$sheet['name']} / {$coordinate}: bold");
                        }
                    }
                }
            }
        }
    }

    public function test_rows_values_totals_and_highlights_are_in_the_preview(): void
    {
        $data = $this->preview(['scope' => 'company', 'client_company_id' => $this->a->id])->assertOk()->json('data');
        $list = collect($data['sheets'])->firstWhere('name', 'Payment list');
        $text = fn (array $row): array => array_map(fn ($cell) => $cell === null ? '' : $cell[0], $row);

        // 3 Permanent employees + the Temporary cover of the first one.
        $this->assertSame(4, $data['row_count']);
        $table = array_slice($list['rows'], 4, 4);
        $this->assertSame(['Employee', 'NUMBER'], array_slice($text($list['rows'][3]), 0, 2));
        $this->assertSame(['Permanent', 'Permanent', 'Permanent', 'Temporary'], collect($table)->map(fn (array $row): string => $text($row)[5])->sort()->values()->all());

        $labels = array_map(fn (array $r): string => $r[0] ?? '', array_map($text, $list['rows']));
        $this->assertContains('Permanent employees total (this record)', $labels);
        $this->assertContains('Combined total', $labels);

        // The money cells show what Excel shows: a dollar sign and two decimals. The covered Permanent is paid 406.45, the Temporary 193.55.
        $money = array_column(array_map($text, $table), 4);
        $this->assertContains('$406.45', $money);
        $this->assertContains('$193.55', $money);

        // A text that looks like a formula is shown as text; the employee without a number has a blank NUMBER cell that carries the highlight.
        $numbers = array_column(array_map($text, $table), 1);
        $this->assertContains('=1+1', $numbers);
        $this->assertContains('T1234567', $numbers);
        $highlightedBlanks = array_filter($table, fn (array $row): bool => ($row[1][0] ?? null) === '' && ($list['styles'][$row[1][1]]['fill'] ?? null) === 'FFF2CC');
        $this->assertCount(1, $highlightedBlanks);
    }

    public function test_the_download_after_a_preview_carries_the_previews_generated_stamp_and_is_audited_once(): void
    {
        $data = $this->preview(['scope' => 'all'])->assertOk()->json('data');
        $book = $this->book($this->download(['scope' => 'all', 'preview_hash' => $data['content_hash'], 'generated_at' => $data['generated_at']])->assertOk());

        $summary = $book->getSheetByName('Summary');
        $this->assertSame($data['generated_at'], $summary->getCell('B5')->getValue());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::Export->value)->count(), 'the preview wrote no audit entry; the download wrote one');
    }

    public function test_a_download_whose_data_changed_after_the_preview_is_refused_and_nothing_is_audited(): void
    {
        $data = $this->preview(['scope' => 'all'])->assertOk()->json('data');
        $this->permanent($this->b, 999);

        $this->download(['scope' => 'all', 'preview_hash' => $data['content_hash'], 'generated_at' => $data['generated_at']])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'PREVIEW_OUT_OF_DATE');
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::Export->value)->count());

        // A fresh preview shows the new employee and its download is accepted.
        $fresh = $this->preview(['scope' => 'all'])->assertOk()->json('data');
        $this->assertSame($data['row_count'] + 1, $fresh['row_count']);
        $this->assertNotSame($data['content_hash'], $fresh['content_hash']);
        $this->download(['scope' => 'all', 'preview_hash' => $fresh['content_hash'], 'generated_at' => $fresh['generated_at']])->assertOk();
    }

    public function test_a_download_without_a_preview_works_as_before_and_a_malformed_hash_is_refused(): void
    {
        $this->download(['scope' => 'all'])->assertOk();
        $this->download(['scope' => 'all', 'preview_hash' => 'not-a-hash'])->assertStatus(422);
        $this->download(['scope' => 'all', 'generated_at' => 'yesterday'])->assertStatus(422);
    }

    public function test_a_generated_stamp_without_the_previews_hash_is_ignored(): void
    {
        $book = $this->book($this->download(['scope' => 'all', 'generated_at' => '2020-01-01 00:00 UTC'])->assertOk());

        $this->assertSame('2026-12-15 10:00 UTC', $book->getSheetByName('Summary')->getCell('B5')->getValue(), 'the stamp is the server\'s own unless a matching preview vouches for another');
    }

    public function test_the_same_data_gives_the_same_content_hash_and_a_filter_gives_a_different_one(): void
    {
        $all = $this->preview(['scope' => 'all'])->json('data');
        $again = $this->preview(['scope' => 'all'])->json('data');
        $one = $this->preview(['scope' => 'company', 'client_company_id' => $this->b->id])->json('data');

        // The stamp is the minute of "now" (frozen in this test), so identical data is identical content.
        $this->assertSame($all['content_hash'], $again['content_hash']);
        $this->assertNotSame($all['content_hash'], $one['content_hash']);
        $this->assertSame(1, $one['row_count']);
    }

    public function test_a_company_with_nothing_to_pay_says_so_and_a_wrong_kind_is_refused(): void
    {
        $empty = $this->makeCompany('Empty Co');
        $this->preview(['scope' => 'company', 'client_company_id' => $empty->id])->assertStatus(422)->assertJsonPath('error_code', 'NO_PAYMENT_RECORDS');
        $this->preview(['scope' => 'guri', 'client_company_id' => $this->a->id])->assertStatus(422);
        $this->preview(['scope' => 'company'])->assertStatus(422);
    }

    public function test_the_preview_needs_the_same_permissions_as_the_download_and_stores_nothing(): void
    {
        $payrollOnly = $this->tokenWithOnly(['hr.view', 'hr.payroll.view']);
        $none = $this->noPermissionToken();

        $this->preview(['scope' => 'all'], $payrollOnly)->assertForbidden();
        $this->preview(['scope' => 'all'], $none)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson(self::PREVIEW.'?period='.self::P)->assertUnauthorized();

        $allowed = $this->tokenWithOnly(['hr.view', 'hr.manage', 'hr.payroll.view']);
        $this->preview(['scope' => 'all'], $allowed)->assertOk();
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::Export->value)->count());
        $this->assertSame([], glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'payment-preview*') ?: [], 'no preview file is left behind');
    }
}
