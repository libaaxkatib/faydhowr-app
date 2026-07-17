<?php

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\NormalBalance;
use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Admin;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_chart_of_accounts_endpoint_lists_accounts_ordered_by_code(): void
    {
        Account::factory()->create(['code' => '4200', 'name' => 'Sales Revenue']);
        Account::factory()->create(['code' => '1110', 'name' => 'Cash']);

        $this
            ->withToken($this->superAdminToken())
            ->getJson('/api/v1/admin/accounting/accounts')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Accounts retrieved successfully.')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', '1110')
            ->assertJsonPath('data.1.code', '4200')
            ->assertJsonStructure(['data' => [[
                'id', 'code', 'name', 'account_type', 'account_category',
                'parent_account_id', 'is_group', 'normal_balance', 'status', 'created_at',
            ]]]);
    }

    public function test_ledger_balance_endpoint_returns_the_derived_balance(): void
    {
        $cash = Account::factory()->create(['code' => '1110', 'name' => 'Cash']);
        $revenue = Account::factory()->normalBalance(NormalBalance::Credit)->create(['code' => '4200']);

        $this->postedEntry('2026-07-10', [
            [$cash, '750.00', '0.00'],
            [$revenue, '0.00', '750.00'],
        ]);

        $this
            ->withToken($this->superAdminToken())
            ->getJson("/api/v1/admin/accounting/accounts/{$cash->id}/balance")
            ->assertOk()
            ->assertJsonPath('data.account_id', $cash->id)
            ->assertJsonPath('data.total_debit', '750.00')
            ->assertJsonPath('data.total_credit', '0.00')
            ->assertJsonPath('data.current_balance', '750.00');
    }

    public function test_journal_entries_index_returns_latest_entries_with_lines(): void
    {
        $cash = Account::factory()->create(['code' => '1110']);
        $revenue = Account::factory()->normalBalance(NormalBalance::Credit)->create(['code' => '4200']);

        $older = $this->postedEntry('2026-07-01', [
            [$cash, '100.00', '0.00'],
            [$revenue, '0.00', '100.00'],
        ]);
        $newer = $this->postedEntry('2026-07-15', [
            [$cash, '200.00', '0.00'],
            [$revenue, '0.00', '200.00'],
        ]);

        $this
            ->withToken($this->superAdminToken())
            ->getJson('/api/v1/admin/accounting/journal-entries')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonCount(2, 'data.0.lines');

        $this
            ->withToken($this->superAdminToken())
            ->getJson('/api/v1/admin/accounting/journal-entries?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id);
    }

    public function test_journal_entries_index_rejects_an_invalid_limit(): void
    {
        $this
            ->withToken($this->superAdminToken())
            ->getJson('/api/v1/admin/accounting/journal-entries?limit=501')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_journal_entry_show_returns_the_entry_with_lines(): void
    {
        $cash = Account::factory()->create(['code' => '1110']);
        $revenue = Account::factory()->normalBalance(NormalBalance::Credit)->create(['code' => '4200']);

        $entry = $this->postedEntry('2026-07-10', [
            [$cash, '500.00', '0.00'],
            [$revenue, '0.00', '500.00'],
        ]);

        $this
            ->withToken($this->superAdminToken())
            ->getJson("/api/v1/admin/accounting/journal-entries/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $entry->id)
            ->assertJsonPath('data.entry_number', $entry->entry_number)
            ->assertJsonPath('data.status', JournalEntryStatus::Posted->value)
            ->assertJsonCount(2, 'data.lines');
    }

    public function test_missing_journal_entry_returns_not_found(): void
    {
        $this
            ->withToken($this->superAdminToken())
            ->getJson('/api/v1/admin/accounting/journal-entries/999999')
            ->assertNotFound();
    }

    public function test_posting_a_balanced_draft_entry_succeeds(): void
    {
        $cash = Account::factory()->create(['code' => '1110']);
        $revenue = Account::factory()->normalBalance(NormalBalance::Credit)->create(['code' => '4200']);

        $entry = $this->entry(JournalEntryStatus::Draft, '2026-07-10', [
            [$cash, '350.00', '0.00'],
            [$revenue, '0.00', '350.00'],
        ]);

        $this
            ->withToken($this->superAdminToken())
            ->postJson("/api/v1/admin/accounting/journal-entries/{$entry->id}/post")
            ->assertOk()
            ->assertJsonPath('message', 'Journal entry posted successfully.')
            ->assertJsonPath('data.status', JournalEntryStatus::Posted->value);

        $this->assertSame(JournalEntryStatus::Posted, $entry->refresh()->status);
    }

    public function test_posting_a_posted_entry_returns_conflict(): void
    {
        $cash = Account::factory()->create(['code' => '1110']);
        $revenue = Account::factory()->normalBalance(NormalBalance::Credit)->create(['code' => '4200']);

        $entry = $this->postedEntry('2026-07-10', [
            [$cash, '350.00', '0.00'],
            [$revenue, '0.00', '350.00'],
        ]);

        $this
            ->withToken($this->superAdminToken())
            ->postJson("/api/v1/admin/accounting/journal-entries/{$entry->id}/post")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'INVALID_JOURNAL_STATUS');
    }

    public function test_posting_an_unbalanced_entry_returns_unprocessable(): void
    {
        $cash = Account::factory()->create(['code' => '1110']);
        $revenue = Account::factory()->normalBalance(NormalBalance::Credit)->create(['code' => '4200']);

        $entry = $this->entry(JournalEntryStatus::Draft, '2026-07-10', [
            [$cash, '350.00', '0.00'],
            [$revenue, '0.00', '100.00'],
        ]);

        $this
            ->withToken($this->superAdminToken())
            ->postJson("/api/v1/admin/accounting/journal-entries/{$entry->id}/post")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'JOURNAL_NOT_BALANCED');

        $this->assertSame(JournalEntryStatus::Draft, $entry->refresh()->status);
    }

    public function test_accounting_periods_index_lists_periods(): void
    {
        AccountingPeriod::factory()->spanning('2026-06-01', '2026-06-30')->create();
        AccountingPeriod::factory()->spanning('2026-07-01', '2026-07-31')->create();

        $this
            ->withToken($this->superAdminToken())
            ->getJson('/api/v1/admin/accounting/periods')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.start_date', '2026-07-01')
            ->assertJsonPath('data.1.start_date', '2026-06-01');
    }

    public function test_accounting_period_can_be_created(): void
    {
        $this
            ->withToken($this->superAdminToken())
            ->postJson('/api/v1/admin/accounting/periods', [
                'name' => 'July 2026',
                'start_date' => '2026-07-01',
                'end_date' => '2026-07-31',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'July 2026')
            ->assertJsonPath('data.start_date', '2026-07-01')
            ->assertJsonPath('data.end_date', '2026-07-31')
            ->assertJsonPath('data.status', AccountingPeriodStatus::Open->value);

        $this->assertDatabaseCount('accounting_periods', 1);
    }

    public function test_overlapping_accounting_period_is_rejected(): void
    {
        AccountingPeriod::factory()->spanning('2026-07-01', '2026-07-31')->create();

        $this
            ->withToken($this->superAdminToken())
            ->postJson('/api/v1/admin/accounting/periods', [
                'name' => 'Mid July 2026',
                'start_date' => '2026-07-15',
                'end_date' => '2026-08-15',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'OVERLAPPING_ACCOUNTING_PERIOD');

        $this->assertDatabaseCount('accounting_periods', 1);
    }

    public function test_accounting_period_validation_rejects_an_inverted_range(): void
    {
        $this
            ->withToken($this->superAdminToken())
            ->postJson('/api/v1/admin/accounting/periods', [
                'name' => 'Broken',
                'start_date' => '2026-07-31',
                'end_date' => '2026-07-01',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_trial_balance_endpoint_supports_all_time_date_range_and_period(): void
    {
        $period = AccountingPeriod::factory()->spanning('2026-07-01', '2026-07-31')->create();
        $cash = Account::factory()->create(['code' => '1110']);
        $revenue = Account::factory()->normalBalance(NormalBalance::Credit)->create(['code' => '4200']);

        $this->postedEntry('2026-06-15', [
            [$cash, '100.00', '0.00'],
            [$revenue, '0.00', '100.00'],
        ]);
        $this->postedEntry('2026-07-15', [
            [$cash, '400.00', '0.00'],
            [$revenue, '0.00', '400.00'],
        ]);

        $token = $this->superAdminToken();

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/accounting/trial-balance')
            ->assertOk()
            ->assertJsonPath('data.total_debit', '500.00')
            ->assertJsonPath('data.total_credit', '500.00')
            ->assertJsonPath('data.is_balanced', true)
            ->assertJsonCount(2, 'data.rows');

        $this
            ->withToken($token)
            ->getJson('/api/v1/admin/accounting/trial-balance?date_from=2026-07-01&date_to=2026-07-31')
            ->assertOk()
            ->assertJsonPath('data.total_debit', '400.00')
            ->assertJsonPath('data.start_date', '2026-07-01')
            ->assertJsonPath('data.end_date', '2026-07-31');

        $this
            ->withToken($token)
            ->getJson("/api/v1/admin/accounting/trial-balance?period_id={$period->id}")
            ->assertOk()
            ->assertJsonPath('data.total_debit', '400.00')
            ->assertJsonPath('data.start_date', '2026-07-01')
            ->assertJsonPath('data.end_date', '2026-07-31');
    }

    public function test_trial_balance_rejects_a_period_combined_with_dates(): void
    {
        $period = AccountingPeriod::factory()->spanning('2026-07-01', '2026-07-31')->create();

        $this
            ->withToken($this->superAdminToken())
            ->getJson("/api/v1/admin/accounting/trial-balance?period_id={$period->id}&date_from=2026-07-01")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_trial_balance_rejects_an_unknown_period(): void
    {
        $this
            ->withToken($this->superAdminToken())
            ->getJson('/api/v1/admin/accounting/trial-balance?period_id=999999')
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');
    }

    public function test_income_statement_endpoint_reports_profit(): void
    {
        $revenue = Account::factory()
            ->category(AccountCategory::Revenue)
            ->normalBalance(NormalBalance::Credit)
            ->create(['code' => '4200']);
        $expense = Account::factory()
            ->category(AccountCategory::Expenses)
            ->create(['code' => '5200']);
        $cash = Account::factory()
            ->category(AccountCategory::Assets)
            ->create(['code' => '1110']);

        $this->postedEntry('2026-07-10', [
            [$cash, '900.00', '0.00'],
            [$revenue, '0.00', '900.00'],
        ]);
        $this->postedEntry('2026-07-12', [
            [$expense, '300.00', '0.00'],
            [$cash, '0.00', '300.00'],
        ]);

        $this
            ->withToken($this->superAdminToken())
            ->getJson('/api/v1/admin/accounting/reports/income-statement')
            ->assertOk()
            ->assertJsonPath('data.total_revenue', '900.00')
            ->assertJsonPath('data.total_expenses', '300.00')
            ->assertJsonPath('data.net_profit', '600.00');
    }

    public function test_balance_sheet_endpoint_satisfies_the_accounting_equation(): void
    {
        $cash = Account::factory()
            ->category(AccountCategory::Assets)
            ->create(['code' => '1110']);
        $capital = Account::factory()
            ->category(AccountCategory::Equity)
            ->normalBalance(NormalBalance::Credit)
            ->create(['code' => '3100']);
        $revenue = Account::factory()
            ->category(AccountCategory::Revenue)
            ->normalBalance(NormalBalance::Credit)
            ->create(['code' => '4200']);

        $this->postedEntry('2026-07-05', [
            [$cash, '500.00', '0.00'],
            [$capital, '0.00', '500.00'],
        ]);
        $this->postedEntry('2026-07-10', [
            [$cash, '200.00', '0.00'],
            [$revenue, '0.00', '200.00'],
        ]);

        $this
            ->withToken($this->superAdminToken())
            ->getJson('/api/v1/admin/accounting/reports/balance-sheet')
            ->assertOk()
            ->assertJsonPath('data.total_assets', '700.00')
            ->assertJsonPath('data.total_liabilities', '0.00')
            ->assertJsonPath('data.total_equity', '700.00')
            ->assertJsonPath('data.current_earnings', '200.00')
            ->assertJsonPath('data.is_balanced', true);
    }

    public function test_admin_without_the_accounting_permission_is_rejected(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Sales]);
        $token = $admin->createToken('admin-panel')->plainTextToken;

        foreach ([
            ['getJson', '/api/v1/admin/accounting/accounts'],
            ['getJson', '/api/v1/admin/accounting/journal-entries'],
            ['getJson', '/api/v1/admin/accounting/periods'],
            ['getJson', '/api/v1/admin/accounting/trial-balance'],
            ['getJson', '/api/v1/admin/accounting/reports/income-statement'],
            ['getJson', '/api/v1/admin/accounting/reports/balance-sheet'],
        ] as [$method, $endpoint]) {
            $this
                ->withToken($token)
                ->{$method}($endpoint)
                ->assertStatus(403)
                ->assertJsonPath('error_code', 'FORBIDDEN');
        }
    }

    public function test_admin_with_a_direct_accounting_permission_is_allowed(): void
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Accountant]);

        DB::table('admin_permissions')->insert([
            'admin_id' => $admin->id,
            'permission_id' => $this->accountingViewPermissionId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/admin/accounting/accounts')
            ->assertOk();
    }

    public function test_customers_cannot_access_accounting_endpoints(): void
    {
        $user = User::factory()->create();

        $this
            ->withToken($user->createToken('store')->plainTextToken)
            ->getJson('/api/v1/admin/accounting/accounts')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this
            ->getJson('/api/v1/admin/accounting/accounts')
            ->assertStatus(401);
    }

    private function superAdminToken(): string
    {
        return Admin::factory()->superAdmin()->create()
            ->createToken('admin-panel')
            ->plainTextToken;
    }

    private function accountingViewPermissionId(): int
    {
        return (int) Permission::query()
            ->where('key', AdminPermission::AccountingView->value)
            ->value('id');
    }

    /**
     * @param  list<array{0: Account, 1: string, 2: string}>  $lines
     */
    private function postedEntry(string $entryDate, array $lines): JournalEntry
    {
        return $this->entry(JournalEntryStatus::Posted, $entryDate, $lines);
    }

    /**
     * @param  list<array{0: Account, 1: string, 2: string}>  $lines
     */
    private function entry(JournalEntryStatus $status, string $entryDate, array $lines): JournalEntry
    {
        $entry = JournalEntry::factory()->status($status)->create(['entry_date' => $entryDate]);

        foreach ($lines as [$account, $debit, $credit]) {
            JournalEntryLine::factory()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $account->id,
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }

        return $entry;
    }
}
