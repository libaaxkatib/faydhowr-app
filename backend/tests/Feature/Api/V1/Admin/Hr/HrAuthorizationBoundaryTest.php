<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeDocument;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 7 security audit (P7-10): the HR test suite previously had almost no
 * authorization-boundary coverage (0 tests asserting 401, only a handful
 * asserting 403). This file exercises a representative route from every HR
 * feature area against both an unauthenticated request (401) and an
 * authenticated admin holding the WRONG permission (403), so a future
 * mis-permissioned route or a broken middleware chain is caught here rather
 * than discovered in production. No business rule, route, or permission key
 * is changed by this file - it only adds test coverage.
 */
class HrAuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => 'active',
            'application_date' => now()->toDateString(),
        ]);
    }

    private function noPermissionToken(): string
    {
        return Admin::factory()->create(['role' => AdminRole::Sales])->createToken('t')->plainTextToken;
    }

    private function tokenWithOnly(AdminPermission $permission): string
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Manager]);

        DB::table('admin_permissions')->insert([
            'admin_id' => $admin->id,
            'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $admin->createToken('t')->plainTextToken;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function hrViewRoutes(int $employeeId, int $employeeCategoryId): array
    {
        return [
            ['get', '/api/v1/admin/hr/dashboard'],
            ['get', '/api/v1/admin/hr/employees'],
            ['get', "/api/v1/admin/hr/employees/{$employeeId}"],
            ['get', "/api/v1/admin/hr/employees/{$employeeId}/guarantor"],
            ['get', "/api/v1/admin/hr/employees/{$employeeId}/contracts"],
            ['get', "/api/v1/admin/hr/employees/{$employeeId}/uniform"],
            ['get', "/api/v1/admin/hr/employees/{$employeeId}/payroll-summary?work_assignment_id=1&period=2026-01"],
            ['get', '/api/v1/admin/hr/training-batches'],
            ['get', '/api/v1/admin/hr/workforce-requests'],
            ['get', '/api/v1/admin/hr/temporary-replacements'],
            ['get', '/api/v1/admin/hr/attendance?date=2026-01-01'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function hrManageRoutes(int $employeeId): array
    {
        return [
            ['post', '/api/v1/admin/hr/departments', ['name' => 'X']],
            ['post', '/api/v1/admin/hr/employees', []],
            ['patch', "/api/v1/admin/hr/employees/{$employeeId}/status", []],
            ['patch', "/api/v1/admin/hr/employees/{$employeeId}/separate", []],
            ['post', "/api/v1/admin/hr/employees/{$employeeId}/attendance", []],
            ['post', "/api/v1/admin/hr/employees/{$employeeId}/leaves", []],
            ['post', "/api/v1/admin/hr/employees/{$employeeId}/payments", []],
            ['post', "/api/v1/admin/hr/employees/{$employeeId}/penalties", []],
            ['post', "/api/v1/admin/hr/employees/{$employeeId}/advances", []],
            ['post', "/api/v1/admin/hr/employees/{$employeeId}/documents", []],
            ['post', "/api/v1/admin/hr/employees/{$employeeId}/guarantor", []],
            ['post', "/api/v1/admin/hr/employees/{$employeeId}/contracts", []],
            ['post', '/api/v1/admin/hr/training-batches', []],
            ['post', '/api/v1/admin/hr/workforce-requests', []],
            ['post', '/api/v1/admin/hr/temporary-replacements', []],
            ['post', "/api/v1/admin/hr/employees/{$employeeId}/work-assignments", []],
        ];
    }

    private function hrReportsRoutes(): array
    {
        return [
            ['get', '/api/v1/admin/hr/reports/summary'],
            ['get', '/api/v1/admin/hr/reports/waiting-roster'],
            ['get', '/api/v1/admin/hr/reports/financial-ledger'],
            ['get', '/api/v1/admin/hr/reports/payroll-rollup?period=2026-01'],
        ];
    }

    public function test_hr_view_routes_reject_a_request_with_no_token_at_all(): void
    {
        $employee = $this->makeEmployee();

        foreach ($this->hrViewRoutes($employee->id, $employee->employee_category_id) as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(401);
        }
    }

    public function test_hr_manage_routes_reject_a_request_with_no_token_at_all(): void
    {
        $employee = $this->makeEmployee();

        foreach ($this->hrManageRoutes($employee->id) as [$method, $uri, $body]) {
            $this->json($method, $uri, $body)->assertStatus(401);
        }
    }

    public function test_hr_reports_routes_reject_a_request_with_no_token_at_all(): void
    {
        foreach ($this->hrReportsRoutes() as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(401);
        }
    }

    public function test_hr_view_routes_reject_an_admin_with_no_hr_permission(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->noPermissionToken();

        foreach ($this->hrViewRoutes($employee->id, $employee->employee_category_id) as [$method, $uri]) {
            $this->withToken($token)->json($method, $uri)->assertStatus(403);
        }
    }

    public function test_hr_manage_routes_reject_an_admin_with_only_hr_view(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->tokenWithOnly(AdminPermission::HrView);

        foreach ($this->hrManageRoutes($employee->id) as [$method, $uri, $body]) {
            $this->withToken($token)->json($method, $uri, $body)->assertStatus(403);
        }
    }

    public function test_hr_reports_routes_reject_an_admin_with_only_hr_view(): void
    {
        $token = $this->tokenWithOnly(AdminPermission::HrView);

        foreach ($this->hrReportsRoutes() as [$method, $uri]) {
            $this->withToken($token)->json($method, $uri)->assertStatus(403);
        }
    }

    public function test_hr_view_routes_reject_an_admin_with_only_hr_manage(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->tokenWithOnly(AdminPermission::HrManage);

        foreach ($this->hrViewRoutes($employee->id, $employee->employee_category_id) as [$method, $uri]) {
            $this->withToken($token)->json($method, $uri)->assertStatus(403);
        }
    }

    private function makeDocument(Employee $employee): EmployeeDocument
    {
        $uploader = Admin::factory()->create();

        return EmployeeDocument::query()->create([
            'employee_id' => $employee->id,
            'admin_id' => $uploader->id,
            'file_name' => 'id-front.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 100,
            'file_path' => 'employees/'.$employee->id.'/documents/id-front.pdf',
            'verification_status' => 'pending',
            'is_current' => true,
        ]);
    }

    public function test_document_download_of_a_real_document_rejects_an_admin_with_no_hr_permission(): void
    {
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee);

        $this->withToken($this->noPermissionToken())
            ->getJson("/api/v1/admin/hr/employees/{$employee->id}/documents/{$document->id}/download")
            ->assertStatus(403);
    }

    public function test_document_download_of_a_real_document_rejects_a_request_with_no_token_at_all(): void
    {
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee);

        $this->getJson("/api/v1/admin/hr/employees/{$employee->id}/documents/{$document->id}/download")
            ->assertStatus(401);
    }
}
