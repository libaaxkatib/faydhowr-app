<?php

namespace Tests\Feature\Api\V1\Admin\Reconciliation;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\DataIssue;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Data Issues & Reconciliation Center: model/relationship, CRUD, permission,
 * status-transition, affected-record, audit-trail, and validation coverage.
 * This is a brand-new internal tool — none of these tests touch or depend
 * on real HR production data.
 */
class DataIssueTest extends TestCase
{
    use RefreshDatabase;

    private function tokenWithOnly(AdminPermission ...$permissions): string
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Manager]);

        foreach ($permissions as $permission) {
            DB::table('admin_permissions')->insert([
                'admin_id' => $admin->id,
                'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $admin->createToken('t')->plainTextToken;
    }

    private function category(): EmployeeCategory
    {
        return EmployeeCategory::query()->firstOrCreate(['name' => 'General Cleaning']);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::query()->create(array_merge([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->numerify('6########'),
            'employee_category_id' => $this->category()->id,
            'status' => 'applicant',
        ], $overrides));
    }

    private function baseIssuePayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Historical Completion Count Discrepancy',
            'description' => 'Union count dropped after duplicate cleanup.',
            'module' => 'hr',
            'issue_type' => 'count_discrepancy',
            'severity' => 'medium',
            'expected_value' => '1,316 employees / 3,948 completion records',
            'actual_value' => '1,310 employees / 3,930 completion records',
            'difference_value' => '6 employees / 18 records',
        ], $overrides);
    }

    // --- Model / relationships ---

    public function test_issue_number_is_generated_sequentially(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate);

        $r1 = $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload())->assertCreated();
        $r2 = $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload())->assertCreated();

        self::assertSame('DI-000001', $r1->json('data.issue_number'));
        self::assertSame('DI-000002', $r2->json('data.issue_number'));
    }

    public function test_affected_record_morphs_to_employee_and_survives_soft_delete(): void
    {
        $employee = $this->makeEmployee(['full_name' => 'Test Employee']);
        $employee->delete(); // soft delete — issue must still show the record

        $issue = DataIssue::query()->create([
            'issue_number' => 'DI-000001', 'title' => 'x', 'module' => 'hr',
            'issue_type' => 'duplicate', 'severity' => 'low', 'status' => 'open',
        ]);
        $issue->affectedRecords()->create([
            'recordable_type' => 'employee', 'recordable_id' => $employee->id, 'created_at' => now(),
        ]);

        $loaded = $issue->fresh(['affectedRecords.recordable']);
        self::assertNotNull($loaded->affectedRecords->first()->recordable);
        self::assertSame('Test Employee', $loaded->affectedRecords->first()->recordable->full_name);
    }

    // --- Permissions ---

    public function test_index_requires_reconciliation_view_permission(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::HrView);

        $this->withToken($token)->getJson('/api/v1/admin/reconciliation/data-issues')->assertStatus(403);
    }

    public function test_store_requires_reconciliation_create_permission(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationView);

        $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload())
            ->assertStatus(403);
    }

    public function test_update_requires_reconciliation_update_permission(): void
    {
        $createToken = $this->tokenWithOnly(AdminPermission::ReconciliationCreate);
        $issueId = $this->withToken($createToken)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload())->json('data.id');

        $viewOnlyToken = $this->tokenWithOnly(AdminPermission::ReconciliationView);
        $this->withToken($viewOnlyToken)->putJson("/api/v1/admin/reconciliation/data-issues/{$issueId}", ['title' => 'New'])
            ->assertStatus(403);
    }

    public function test_resolve_requires_reconciliation_resolve_permission_not_just_update(): void
    {
        $createToken = $this->tokenWithOnly(AdminPermission::ReconciliationCreate);
        $issueId = $this->withToken($createToken)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload())->json('data.id');

        $updateOnlyToken = $this->tokenWithOnly(AdminPermission::ReconciliationUpdate);
        $this->withToken($updateOnlyToken)
            ->patchJson("/api/v1/admin/reconciliation/data-issues/{$issueId}/resolve", ['status' => 'resolved', 'resolution' => 'Fixed.'])
            ->assertStatus(403);
    }

    // --- CRUD ---

    public function test_full_crud_lifecycle(): void
    {
        $token = $this->tokenWithOnly(
            AdminPermission::ReconciliationView,
            AdminPermission::ReconciliationCreate,
            AdminPermission::ReconciliationUpdate,
            AdminPermission::ReconciliationResolve,
        );
        $employee = $this->makeEmployee();

        $create = $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload([
            'affected_employees' => [['employee_id' => $employee->id, 'context_note' => 'Lost historical completion eligibility.']],
        ]))->assertCreated();

        $issueId = $create->json('data.id');
        self::assertSame('open', $create->json('data.status'));
        self::assertCount(1, $create->json('data.affected_records'));

        $this->withToken($token)->getJson('/api/v1/admin/reconciliation/data-issues')
            ->assertOk()
            ->assertJsonPath('data.0.issue_number', 'DI-000001');

        $this->withToken($token)->getJson("/api/v1/admin/reconciliation/data-issues/{$issueId}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Historical Completion Count Discrepancy');

        $this->withToken($token)->putJson("/api/v1/admin/reconciliation/data-issues/{$issueId}", [
            'status' => 'investigating',
            'root_cause' => 'Duplicate removal orphaned status-history evidence.',
        ])->assertOk()->assertJsonPath('data.status', 'investigating');

        $resolve = $this->withToken($token)->patchJson("/api/v1/admin/reconciliation/data-issues/{$issueId}/resolve", [
            'status' => 'cannot_resolve',
            'resolution' => 'Predates today\'s removals; root cause not fully isolated within available evidence.',
        ])->assertOk();

        self::assertSame('cannot_resolve', $resolve->json('data.status'));
        self::assertNotNull($resolve->json('data.resolved_at'));
        self::assertNotNull($resolve->json('data.resolved_by'));
    }

    // --- Status transitions ---

    public function test_status_cannot_be_set_to_a_closing_value_via_general_update(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate, AdminPermission::ReconciliationUpdate);
        $issueId = $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload())->json('data.id');

        $this->withToken($token)->putJson("/api/v1/admin/reconciliation/data-issues/{$issueId}", ['status' => 'resolved'])
            ->assertStatus(422);
    }

    public function test_resolve_endpoint_rejects_a_non_closing_status(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate, AdminPermission::ReconciliationResolve);
        $issueId = $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload())->json('data.id');

        $this->withToken($token)->patchJson("/api/v1/admin/reconciliation/data-issues/{$issueId}/resolve", [
            'status' => 'investigating', 'resolution' => 'x',
        ])->assertStatus(422);
    }

    public function test_reopening_a_resolved_issue_clears_resolved_by_and_resolved_at(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate, AdminPermission::ReconciliationUpdate, AdminPermission::ReconciliationResolve);
        $issueId = $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload())->json('data.id');

        $this->withToken($token)->patchJson("/api/v1/admin/reconciliation/data-issues/{$issueId}/resolve", [
            'status' => 'resolved', 'resolution' => 'Done.',
        ])->assertOk();

        $reopened = $this->withToken($token)->putJson("/api/v1/admin/reconciliation/data-issues/{$issueId}", ['status' => 'investigating'])
            ->assertOk();

        self::assertNull($reopened->json('data.resolved_at'));
        self::assertNull($reopened->json('data.resolved_by'));
    }

    public function test_new_issue_cannot_be_created_directly_in_a_closing_status(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate);

        $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload(['status' => 'resolved']))
            ->assertStatus(422);
    }

    // --- Affected records ---

    public function test_duplicate_affected_employee_link_is_rejected_silently_not_duplicated(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate);
        $employee = $this->makeEmployee();

        $create = $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload([
            'affected_employees' => [
                ['employee_id' => $employee->id],
                ['employee_id' => $employee->id],
            ],
        ]))->assertCreated();

        self::assertCount(1, $create->json('data.affected_records'));
    }

    public function test_affected_employees_can_be_added_and_removed_via_update(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate, AdminPermission::ReconciliationUpdate);
        $employeeA = $this->makeEmployee();
        $employeeB = $this->makeEmployee();

        $create = $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload([
            'affected_employees' => [['employee_id' => $employeeA->id]],
        ]))->assertCreated();
        $issueId = $create->json('data.id');
        $recordId = $create->json('data.affected_records.0.id');

        $updated = $this->withToken($token)->putJson("/api/v1/admin/reconciliation/data-issues/{$issueId}", [
            'add_affected_employees' => [['employee_id' => $employeeB->id]],
            'remove_affected_record_ids' => [$recordId],
        ])->assertOk();

        $employeeIds = collect($updated->json('data.affected_records'))->pluck('employee.id')->all();
        self::assertSame([$employeeB->id], $employeeIds);
    }

    public function test_affected_records_show_actual_employee_detail_not_only_a_count(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate, AdminPermission::ReconciliationView);
        $employee = $this->makeEmployee(['full_name' => 'Findable Person']);

        $create = $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload([
            'affected_employees' => [['employee_id' => $employee->id, 'context_note' => 'Missing phone']],
        ]))->assertCreated();

        $detail = $this->withToken($token)->getJson("/api/v1/admin/reconciliation/data-issues/{$create->json('data.id')}")->assertOk();

        self::assertSame('Findable Person', $detail->json('data.affected_records.0.employee.full_name'));
        self::assertSame($employee->employee_number, $detail->json('data.affected_records.0.employee.employee_number'));
        self::assertSame('Missing phone', $detail->json('data.affected_records.0.context_note'));
    }

    // --- Audit trail ---

    public function test_status_change_is_recorded_in_audit_trail_with_old_and_new_values(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate, AdminPermission::ReconciliationUpdate, AdminPermission::ReconciliationView);
        $issueId = $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload())->json('data.id');

        $this->withToken($token)->putJson("/api/v1/admin/reconciliation/data-issues/{$issueId}", ['status' => 'investigating'])->assertOk();

        $detail = $this->withToken($token)->getJson("/api/v1/admin/reconciliation/data-issues/{$issueId}")->assertOk();
        $statusEntry = collect($detail->json('data.audit_logs'))->firstWhere('field_changed', 'status');

        self::assertNotNull($statusEntry);
        self::assertSame('open', $statusEntry['old_value']);
        self::assertSame('investigating', $statusEntry['new_value']);
    }

    public function test_unchanged_fields_do_not_produce_audit_entries(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate, AdminPermission::ReconciliationUpdate, AdminPermission::ReconciliationView);
        $issueId = $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload())->json('data.id');

        // Same title as already set — must not produce a "no-op" audit row.
        $this->withToken($token)->putJson("/api/v1/admin/reconciliation/data-issues/{$issueId}", [
            'title' => 'Historical Completion Count Discrepancy',
        ])->assertOk();

        $detail = $this->withToken($token)->getJson("/api/v1/admin/reconciliation/data-issues/{$issueId}")->assertOk();
        self::assertCount(0, $detail->json('data.audit_logs'));
    }

    public function test_issue_creation_never_modifies_the_affected_employee(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate);
        $employee = $this->makeEmployee(['status' => 'applicant', 'phone' => '615000111']);

        $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload([
            'affected_employees' => [['employee_id' => $employee->id]],
        ]))->assertCreated();

        $employee->refresh();
        self::assertSame('applicant', $employee->status->value);
        self::assertSame('615000111', $employee->phone);
    }

    // --- Validation ---

    public function test_store_rejects_invalid_module(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate);

        $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload(['module' => 'not_a_real_module']))
            ->assertStatus(422);
    }

    public function test_store_rejects_nonexistent_affected_employee(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate);

        $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload([
            'affected_employees' => [['employee_id' => 999999]],
        ]))->assertStatus(422);
    }

    public function test_resolve_requires_a_resolution_text(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate, AdminPermission::ReconciliationResolve);
        $issueId = $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload())->json('data.id');

        $this->withToken($token)->patchJson("/api/v1/admin/reconciliation/data-issues/{$issueId}/resolve", ['status' => 'resolved'])
            ->assertStatus(422);
    }

    // --- Summary / filters / search ---

    public function test_summary_counts_by_status_and_severity(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate, AdminPermission::ReconciliationView, AdminPermission::ReconciliationResolve);

        $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload(['severity' => 'critical']))->assertCreated();
        $secondId = $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload(['severity' => 'low']))->json('data.id');
        $this->withToken($token)->patchJson("/api/v1/admin/reconciliation/data-issues/{$secondId}/resolve", ['status' => 'resolved', 'resolution' => 'x'])->assertOk();

        $summary = $this->withToken($token)->getJson('/api/v1/admin/reconciliation/data-issues/summary')->assertOk();

        self::assertSame(2, $summary->json('data.total'));
        self::assertSame(1, $summary->json('data.open'));
        self::assertSame(1, $summary->json('data.resolved'));
        self::assertSame(1, $summary->json('data.critical'));
    }

    public function test_search_finds_issue_by_affected_employee_name(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate, AdminPermission::ReconciliationView);
        $employee = $this->makeEmployee(['full_name' => 'Uniquely Named Person']);

        $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload([
            'affected_employees' => [['employee_id' => $employee->id]],
        ]))->assertCreated();

        $results = $this->withToken($token)->getJson('/api/v1/admin/reconciliation/data-issues?search=Uniquely Named')->assertOk();

        self::assertCount(1, $results->json('data'));
    }

    public function test_filter_by_status_and_severity(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::ReconciliationCreate, AdminPermission::ReconciliationView);

        $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload(['severity' => 'critical']))->assertCreated();
        $this->withToken($token)->postJson('/api/v1/admin/reconciliation/data-issues', $this->baseIssuePayload(['severity' => 'low']))->assertCreated();

        $results = $this->withToken($token)->getJson('/api/v1/admin/reconciliation/data-issues?severity=critical')->assertOk();

        self::assertCount(1, $results->json('data'));
        self::assertSame('critical', $results->json('data.0.severity'));
    }
}
