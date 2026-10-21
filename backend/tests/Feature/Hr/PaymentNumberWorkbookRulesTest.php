<?php

namespace Tests\Feature\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Support\Hr\Payroll\PaymentNumber;
use App\Support\Hr\WorkbookPlan\WorkbookPlanExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision 6: the `EMPLOYEE NUMBER OR ACCOUNT NUMBER` column of the two workbooks is the authoritative payroll/payment number.
 * An Excel value is recorded exactly as written (text, leading zeros kept) whether or not it agrees with the registration phone; a
 * difference is flagged, never rejected; the phone, the employee number and the internal id are never written; a missing value writes
 * nothing (the payment lists show the phone as a labelled fallback); duplicates and numbers held by someone else go to review; and the
 * dry run reports every column the owner asked for.
 */
class PaymentNumberWorkbookRulesTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $dir;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'paynum_'.uniqid();
        mkdir($this->dir);
        $this->admin = Admin::factory()->create(['role' => AdminRole::SuperAdmin]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @param  list<array{0: Employee, 1: string}>  $pairs employee + the Excel value of its row */
    private function planFile(array $pairs): string
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.'plan.csv';
        $h = fopen($path, 'w');
        fputcsv($h, ['source_row', 'employee_id', 'employee_number', 'name_key', 'expected_status', 'company', 'salary', 'start_date', 'payment_account_number'], ',', '"', '\\');
        foreach ($pairs as $i => [$employee, $value]) {
            fputcsv($h, [$i + 2, $employee->id, $employee->employee_number, WorkbookPlanExecutor::nameKey($employee->full_name), 'active', 'X', '100', '2026-10-01', $value], ',', '"', '\\');
        }
        fclose($h);

        return $path;
    }

    private function plan(string $file, bool $commit = true, array $extra = []): PendingCommand
    {
        $args = ['--employees' => $file, '--step' => ['payments']] + $extra;

        return $commit
            ? $this->artisan('hr:apply-workbook-plan', $args + ['--commit' => true, '--admin-email' => $this->admin->email])
            : $this->artisan('hr:apply-workbook-plan', $args);
    }

    public function test_a_value_that_agrees_with_the_registration_phone_is_recorded_as_a_match(): void
    {
        $e = $this->makeEmployee('active', 'permanent', '615000111');

        $this->plan($this->planFile([[$e, '0615000111']]), true, ['--source-label' => 'EMPLOYEE AND COMPANY.xlsx / Sheet1'])->assertSuccessful();

        $fresh = $e->fresh();
        $this->assertSame('0615000111', $fresh->payment_account_number, 'text exactly as written, leading zero kept');
        $this->assertSame(PaymentNumber::CHECK_MATCH, $fresh->payment_account_register_check);
        $this->assertSame(PaymentNumber::SOURCE_WORKBOOK_PART_ONE, $fresh->payment_account_source);
        $this->assertStringContainsString('row 2', (string) $fresh->payment_account_source_ref);
        $this->assertSame('615000111', $fresh->phone, 'the registration phone is never overwritten');
    }

    public function test_a_value_that_differs_from_the_registration_phone_is_kept_and_flagged_not_rejected(): void
    {
        $e = $this->makeEmployee('active', 'permanent', '615000111');

        $this->plan($this->planFile([[$e, '38628356']]), true, ['--payment-source' => 'part_two'])->assertSuccessful();

        $fresh = $e->fresh();
        $this->assertSame('38628356', $fresh->payment_account_number, 'the Excel value is authoritative');
        $this->assertSame(PaymentNumber::CHECK_MISMATCH, $fresh->payment_account_register_check, 'flagged for review');
        $this->assertSame(PaymentNumber::SOURCE_WORKBOOK_PART_TWO, $fresh->payment_account_source);
        $this->assertSame('615000111', $fresh->phone);
    }

    public function test_a_row_without_an_excel_value_writes_nothing_and_reports_the_phone_fallback(): void
    {
        $e = $this->makeEmployee('active', 'permanent', '615000111');

        $this->plan($this->planFile([[$e, '']]), true)->expectsOutputToContain('fallback_phone: 1')->assertSuccessful();

        $fresh = $e->fresh();
        $this->assertNull($fresh->payment_account_number, 'the phone is never stored as a payment number');
        $this->assertNull($fresh->payment_account_source);
        $this->assertSame('615000111', $fresh->phone);
    }

    public function test_the_same_number_on_two_rows_is_sent_to_review_and_nothing_is_written(): void
    {
        $a = $this->makeEmployee('active', 'permanent', '615000111');
        $b = $this->makeEmployee('active', 'permanent', '615000222');

        $this->plan($this->planFile([[$a, '38628356'], [$b, '38628356']]), true)->expectsOutputToContain('needs_review: 2')->assertSuccessful();

        $this->assertNull($a->fresh()->payment_account_number);
        $this->assertNull($b->fresh()->payment_account_number);
    }

    public function test_a_number_already_held_by_another_employee_is_sent_to_review(): void
    {
        $holder = $this->makeEmployee('active', 'permanent', '615000111');
        $holder->forceFill(['payment_account_number' => '38628356'])->save();
        $other = $this->makeEmployee('active', 'permanent', '615000222');

        $this->plan($this->planFile([[$other, '38628356']]), true)->expectsOutputToContain('needs_review: 1')->assertSuccessful();

        $this->assertNull($other->fresh()->payment_account_number);
        $this->assertSame('38628356', $holder->fresh()->payment_account_number);
    }

    public function test_a_different_stored_number_is_reported_and_only_replaced_with_the_explicit_option_and_an_audit(): void
    {
        $e = $this->makeEmployee('active', 'permanent', '615000111');
        $e->forceFill(['payment_account_number' => '61999999', 'payment_account_source' => PaymentNumber::SOURCE_MANUAL])->save();
        $file = $this->planFile([[$e, '38628356']]);

        $this->plan($file, true)->expectsOutputToContain('PAYMENT_NUMBER_CONFLICT')->assertFailed();
        $this->assertSame('61999999', $e->fresh()->payment_account_number);

        $this->plan($file, true, ['--replace-stored' => true])->assertSuccessful();
        $this->assertSame('38628356', $e->fresh()->payment_account_number);
        $audit = AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $e->id)->latest('id')->first();
        $this->assertTrue($audit->metadata['replaced_stored_number']);
        $this->assertStringNotContainsString('38628356', json_encode($audit->metadata), 'the number itself is not put in the audit trail');
    }

    public function test_a_different_payroll_number_never_moves_a_row_to_another_person(): void
    {
        $a = $this->makeEmployee('active', 'permanent', '615000111');
        $b = $this->makeEmployee('active', 'permanent', '615000222');
        // The row names employee A (number + name) but carries B's phone as its Excel value: identity is by employee, not by the number.
        $this->plan($this->planFile([[$a, '615000222']]), true)->expectsOutputToContain('set: 1')->assertSuccessful();

        $this->assertSame('615000222', $a->fresh()->payment_account_number, 'recorded for the person the row names');
        $this->assertSame(PaymentNumber::CHECK_MISMATCH, $a->fresh()->payment_account_register_check, 'and flagged: it differs from A\'s own phone');
        $this->assertNull($b->fresh()->payment_account_number, 'nothing is recorded for, or moved to, another person');
        $this->assertSame('615000222', $b->fresh()->phone);
    }

    public function test_the_dry_run_report_has_every_requested_column_and_writes_nothing(): void
    {
        $a = $this->makeEmployee('active', 'permanent', '615000111');
        $b = $this->makeEmployee('active', 'permanent', '615000222');
        $report = $this->dir.DIRECTORY_SEPARATOR.'report.csv';

        $this->plan($this->planFile([[$a, '0615000111'], [$b, '']]), false, ['--report' => $report])->assertSuccessful();

        $this->assertNull($a->fresh()->payment_account_number, 'a dry run writes nothing');
        $rows = array_map(fn (string $line) => str_getcsv($line, ',', '"', '\\'), file($report, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $header = array_shift($rows);
        $this->assertSame(['step', 'reference', 'outcome', 'code', 'message', 'employee_number', 'excel_value', 'register_phone', 'register_check', 'stored_value', 'proposed_number', 'fallback_needed', 'source_ref'], $header);
        $byEmployee = collect($rows)->mapWithKeys(fn (array $r): array => [$r[5] => array_combine($header, $r)]);
        $this->assertSame('0615000111', $byEmployee[$a->employee_number]['excel_value'], 'the leading zero survives in the report');
        $this->assertSame('match', $byEmployee[$a->employee_number]['register_check']);
        $this->assertSame('0615000111', $byEmployee[$a->employee_number]['proposed_number']);
        $this->assertSame('would_set', $byEmployee[$a->employee_number]['outcome']);
        $this->assertSame('yes', $byEmployee[$b->employee_number]['fallback_needed']);
        $this->assertSame('fallback_phone', $byEmployee[$b->employee_number]['outcome']);
    }

    public function test_an_unknown_payment_source_is_refused(): void
    {
        $e = $this->makeEmployee('active', 'permanent', '615000111');

        $this->plan($this->planFile([[$e, '38628356']]), false, ['--payment-source' => 'part_three'])->assertFailed();
    }

    public function test_the_register_comparison_ignores_country_codes_and_leading_zeros_but_not_different_numbers(): void
    {
        $this->assertTrue(PaymentNumber::agreesWithRegister('0615000111', '615000111'));
        $this->assertTrue(PaymentNumber::agreesWithRegister('+252 61 5000111', '0615000111'));
        $this->assertTrue(PaymentNumber::agreesWithRegister('615000111', '615000999', '0615000111'), 'the alternate phone counts as the register too');
        $this->assertFalse(PaymentNumber::agreesWithRegister('38628356', '615000111'));
        $this->assertFalse(PaymentNumber::agreesWithRegister('', '615000111'));
        $this->assertFalse(PaymentNumber::agreesWithRegister('61500011', '615000111'), 'a shorter number is not a match by suffix');
    }
}
