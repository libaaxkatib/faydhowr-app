<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\EmployeeCategory;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The clickable HR Dashboard cards open the Employees list with filters (web-panel
 * src/utils/hrDashboardLinks.ts). Each card's number and the list behind it must
 * agree, otherwise the user clicks "12" and lands on 9 rows. These tests send the
 * exact query the destination sends and compare it with the dashboard's own count.
 */
class HrDashboardCardDestinationsTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->token] = $this->superAdminToken();
    }

    private function dashboard(): array
    {
        return $this->withToken($this->token)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data');
    }

    private function listTotal(string $query): int
    {
        return (int) $this->withToken($this->token)->getJson('/api/v1/admin/hr/employees?per_page=100&'.$query)->assertOk()->json('meta.total');
    }

    private function seed30dBoundary(string $status, string $date): void
    {
        $employee = $this->makeEmployee($status);
        $employee->forceFill(['application_date' => $date])->save();
    }

    public function test_status_cards_match_the_employee_list_filtered_by_the_same_status(): void
    {
        foreach (['active', 'active', 'inactive', 'practical', 'practical', 'waiting', 'approved', 'applicant'] as $status) {
            $this->makeEmployee($status);
        }

        $dashboard = $this->dashboard();

        $this->assertSame($dashboard['total_employees'], $this->listTotal(''));
        $this->assertSame(8, $dashboard['total_employees']);
        foreach ([
            'active_employees' => 'active',
            'inactive_employees' => 'inactive',
            'practical' => 'practical',
            'waiting' => 'waiting',
            'approved' => 'approved',
        ] as $metric => $status) {
            $this->assertSame($dashboard[$metric], $this->listTotal("status={$status}"), "{$metric} vs status={$status}");
            $this->assertGreaterThan(0, $dashboard[$metric]);
        }
    }

    public function test_a_card_with_a_zero_count_still_opens_an_empty_but_valid_filtered_list(): void
    {
        $this->makeEmployee('active');

        $dashboard = $this->dashboard();

        $this->assertSame(0, $dashboard['approved']);
        $this->assertSame(0, $this->listTotal('status=approved'));
    }

    public function test_new_applicants_card_matches_applicants_from_the_last_thirty_days(): void
    {
        $since = now()->subDays(30)->toDateString();
        $this->seed30dBoundary('applicant', now()->toDateString());
        $this->seed30dBoundary('applicant', $since); // exactly on the boundary: counted by the card
        $this->seed30dBoundary('applicant', now()->subDays(31)->toDateString()); // too old
        $this->seed30dBoundary('active', now()->toDateString()); // recent but not an applicant

        $dashboard = $this->dashboard();

        $this->assertSame(2, $dashboard['new_applicants']);
        $this->assertSame($dashboard['new_applicants'], $this->listTotal("status=applicant&application_date_from={$since}"));
    }

    public function test_supervisor_pool_card_matches_the_supervisor_filter(): void
    {
        $supervisor = $this->makeEmployee('active');
        $supervisor->forceFill(['is_supervisor' => true])->save();
        $this->makeEmployee('active');

        $dashboard = $this->dashboard();

        $this->assertSame(1, $dashboard['supervisor_pool_count']);
        $this->assertSame($dashboard['supervisor_pool_count'], $this->listTotal('is_supervisor=true'));
    }

    public function test_office_staff_card_matches_the_office_only_filter(): void
    {
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();
        $this->makeAssignment($this->makeEmployee(), null, $office);
        $this->makeEmployee();

        $dashboard = $this->dashboard();

        $this->assertSame(1, $dashboard['office_staff_count']);
        $this->assertSame($dashboard['office_staff_count'], $this->listTotal('office_only=true'));
    }

    public function test_client_company_card_matches_the_client_company_only_filter(): void
    {
        $company = $this->makeCompany('Alpha Hospital');
        $site = $this->makeClientLocation($this->makeCompany('Beta School'));
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();

        $byCompany = $this->makeEmployee();
        $this->makeAssignment($byCompany, $company); // company only, no workplace
        $bySite = $this->makeEmployee();
        $this->makeAssignment($bySite, null, $site); // company comes from the workplace
        $this->makeAssignment($this->makeEmployee(), null, $office); // office is NOT a client company
        $this->makeEmployee(); // unassigned
        $ended = $this->makeEmployee();
        $this->makeAssignment($ended, $company)->forceFill(['status' => 'ended', 'end_date' => '2026-02-01'])->save();

        $dashboard = $this->dashboard();

        $this->assertSame(2, $dashboard['client_company_active_employees']);
        $response = $this->withToken($this->token)->getJson('/api/v1/admin/hr/employees?per_page=100&client_company_only=true')->assertOk();
        $this->assertSame($dashboard['client_company_active_employees'], $response->json('meta.total'));
        $ids = array_column($response->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$byCompany->id, $bySite->id], $ids);
    }

    public function test_the_client_company_filter_accepts_false_without_filtering(): void
    {
        $this->makeEmployee();

        $this->assertSame(1, $this->listTotal('client_company_only=false'));
    }

    public function test_category_breakdown_tiles_match_the_category_filter(): void
    {
        $category = EmployeeCategory::query()->firstOrFail();
        $this->makeEmployee('waiting');
        $this->makeEmployee('active');

        $row = collect($this->dashboard()['category_breakdown'])->firstWhere('id', $category->id);

        $this->assertNotNull($row);
        $this->assertSame($row['total'], $this->listTotal("employee_category_id={$category->id}"));
        $this->assertSame(2, $row['total']);
    }
}
