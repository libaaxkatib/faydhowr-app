<?php

namespace Tests\Unit\Hr\Payroll;

use App\Support\Hr\Payroll\PayrollSheetBuilder;
use App\Support\Hr\Payroll\PayrollWorkbookExporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PayrollSheetTest extends TestCase
{
    /**
     * @return list<array{date: string, verified: bool}>
     */
    private function days(int $from, int $to, bool $verified = true): array
    {
        $days = [];
        for ($d = $from; $d <= $to; $d++) {
            $days[] = ['date' => sprintf('2026-10-%02d', $d), 'verified' => $verified];
        }

        return $days;
    }

    /**
     * @param  list<array<string, mixed>>  $replacements
     * @return array<string, mixed>
     */
    private function permanent(int $id, string $name, array $replacements = [], array $deductions = [], int $salary = 30000): array
    {
        return [
            'employee_id' => $id, 'name' => $name, 'company' => 'TEST COMPANY', 'workplace' => 'Test site',
            'salary_periods' => [['from' => '2020-01-01', 'monthly_cents' => $salary]],
            'employed_from' => '2020-01-01', 'deductions' => $deductions, 'replacements' => $replacements,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function temp(int $id, string $name, string $start, array $workdays, bool $approved = true): array
    {
        return ['replacement_id' => $id * 10, 'employee_id' => $id, 'name' => $name, 'start_date' => $start, 'workdays' => $workdays, 'approved' => $approved];
    }

    public function test_temporaries_sit_under_their_permanent_in_chronological_order(): void
    {
        $sheet = (new PayrollSheetBuilder)->build('2026-10', [
            $this->permanent(1, 'Perm One', [
                $this->temp(902, 'Temp B', '2026-10-15', $this->days(15, 18)),
                $this->temp(901, 'Temp A', '2026-10-05', $this->days(5, 8)),
            ]),
            $this->permanent(2, 'Perm Two'),
        ]);

        $this->assertSame(
            ['Perm One', 'Temp A', 'Temp B', 'Perm Two'],
            array_column($sheet['rows'], 'name'),
        );
        $this->assertSame(['permanent', 'temporary', 'temporary', 'permanent'], array_column($sheet['rows'], 'type'));
        $this->assertSame([1, 1], [$sheet['rows'][1]['parent_employee_id'], $sheet['rows'][2]['parent_employee_id']]);
    }

    public function test_permanent_deduction_equals_the_sum_of_approved_children_and_net_is_gross_minus_deductions(): void
    {
        $sheet = (new PayrollSheetBuilder)->build('2026-10', [
            $this->permanent(1, 'Perm One', [
                $this->temp(901, 'Temp A', '2026-10-05', $this->days(5, 8)),
                $this->temp(902, 'Temp B', '2026-10-15', $this->days(15, 18)),
            ], [['type' => 'Salary advance', 'cents' => 2000]]),
        ]);

        [$perm, $a, $b] = $sheet['rows'];

        $this->assertSame(3871, $a['amount_cents']);
        $this->assertSame(3871, $b['amount_cents']);
        $this->assertSame(7742, $perm['replacement_deduction_cents']);
        $this->assertSame(9742, $perm['total_deductions_cents']);
        $this->assertSame(30000, $perm['gross_cents']);
        $this->assertSame(20258, $perm['net_cents']);
        $this->assertTrue($sheet['reconciliation']['ok']);
    }

    public function test_totals_use_permanent_rows_only_so_nothing_is_counted_twice(): void
    {
        $sheet = (new PayrollSheetBuilder)->build('2026-10', [
            $this->permanent(1, 'Perm One', [$this->temp(901, 'Temp A', '2026-10-05', $this->days(5, 8))]),
            $this->permanent(2, 'Perm Two', [], [], 20000),
        ]);

        $this->assertSame(50000, $sheet['totals']['gross_cents']);
        $this->assertSame(3871, $sheet['totals']['replacement_deduction_cents']);
        $this->assertSame(50000 - 3871, $sheet['totals']['net_cents']);
    }

    public function test_an_unapproved_replacement_is_shown_but_not_deducted(): void
    {
        $sheet = (new PayrollSheetBuilder)->build('2026-10', [
            $this->permanent(1, 'Perm One', [$this->temp(901, 'Temp A', '2026-10-05', $this->days(5, 8), false)]),
        ]);

        $this->assertSame(0, $sheet['rows'][0]['replacement_deduction_cents']);
        $this->assertSame(3871, $sheet['rows'][1]['amount_cents']);
        $this->assertFalse($sheet['rows'][1]['deducted_from_parent']);
        $this->assertStringContainsString('Pending approval', $sheet['rows'][1]['note']);
    }

    public function test_unverified_days_never_reach_the_deduction(): void
    {
        $sheet = (new PayrollSheetBuilder)->build('2026-10', [
            $this->permanent(1, 'Perm One', [$this->temp(901, 'Temp A', '2026-10-05', [...$this->days(5, 6), ...$this->days(7, 9, false)])]),
        ]);

        $this->assertSame(2, $sheet['rows'][1]['verified_days']);
        $this->assertSame(1935, $sheet['rows'][1]['amount_cents']);
    }

    public function test_two_replacements_charging_the_same_day_are_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        (new PayrollSheetBuilder)->build('2026-10', [
            $this->permanent(1, 'Perm One', [
                $this->temp(901, 'Temp A', '2026-10-05', $this->days(5, 10)),
                $this->temp(902, 'Temp B', '2026-10-10', $this->days(10, 12)),
            ]),
        ]);
    }

    public function test_a_negative_net_is_flagged_not_hidden(): void
    {
        $sheet = (new PayrollSheetBuilder)->build('2026-10', [
            $this->permanent(1, 'Perm One', [], [['type' => 'Penalty', 'cents' => 40000]]),
        ]);

        $this->assertSame(-10000, $sheet['rows'][0]['net_cents']);
        $this->assertStringContainsString('Negative net', $sheet['rows'][0]['note']);
    }

    public function test_excel_export_places_children_directly_below_their_parent(): void
    {
        $sheet = (new PayrollSheetBuilder)->build('2026-10', [
            $this->permanent(1, 'Perm One', [
                $this->temp(902, 'Temp B', '2026-10-15', $this->days(15, 18)),
                $this->temp(901, 'Temp A', '2026-10-05', $this->days(5, 8)),
            ]),
            $this->permanent(2, 'Perm Two'),
        ]);
        $path = tempnam(sys_get_temp_dir(), 'payroll').'.xlsx';

        try {
            (new PayrollWorkbookExporter)->write($sheet, $path);
            $ws = IOFactory::load($path)->getActiveSheet();

            $this->assertSame('Permanent', $ws->getCell('C4')->getValue());
            $this->assertSame('Temp A', trim((string) $ws->getCell('B5')->getValue()));
            $this->assertStringStartsWith('Temporary (covering 1)', (string) $ws->getCell('C5')->getValue());
            $this->assertSame('Temp B', trim((string) $ws->getCell('B6')->getValue()));
            $this->assertSame('Perm Two', $ws->getCell('B7')->getValue());
            $this->assertSame('OK', $ws->getCell('T10')->getValue());
        } finally {
            @unlink($path);
        }
    }
}
