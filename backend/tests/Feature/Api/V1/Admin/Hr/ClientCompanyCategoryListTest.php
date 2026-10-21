<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The Companies & Workplaces page filters its list by each record's own `category` (Company / Guri) and shows
 * counts from that same list. These tests pin what that list must contain.
 */
class ClientCompanyCategoryListTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    public function test_every_client_record_carries_its_category_and_the_counts_add_up(): void
    {
        [, $token] = $this->superAdminToken();
        foreach (['ALFARAJ', 'HUMANCARE', 'TCC'] as $name) {
            $this->makeCompany($name, 'company');
        }
        foreach (['GURI ASAD', 'Kinsi'] as $name) {
            $this->makeCompany($name, 'guri');
        }

        $rows = $this->withToken($token)->getJson('/api/v1/admin/hr/client-companies')->assertOk()->json('data');
        $byCategory = array_count_values(array_column($rows, 'category'));

        $this->assertCount(5, $rows);
        $this->assertSame(['company' => 3, 'guri' => 2], $byCategory);
        $this->assertSame(count($rows), array_sum($byCategory));
        $this->assertEqualsCanonicalizing(['ALFARAJ', 'HUMANCARE', 'TCC', 'GURI ASAD', 'Kinsi'], array_column($rows, 'name'), 'exact names preserved');
    }

    public function test_the_fayadhowr_office_is_an_internal_location_and_never_a_client_company(): void
    {
        [, $token] = $this->superAdminToken();
        $this->makeCompany('ALFARAJ');

        $this->assertSame(1, WorkLocation::query()->where('location_type', 'office')->count(), 'the internal office exists as a location');
        $this->assertDatabaseMissing('client_companies', ['name' => 'Fayadhowr Office']);

        $rows = $this->withToken($token)->getJson('/api/v1/admin/hr/client-companies')->assertOk()->json('data');
        $this->assertNotContains('Fayadhowr Office', array_column($rows, 'name'));
        foreach ($rows as $row) {
            $this->assertContains($row['category'], ['company', 'guri']);
        }

        $locations = $this->withToken($token)->getJson('/api/v1/admin/hr/work-locations')->assertOk()->json('data');
        $this->assertContains('office', array_column($locations, 'location_type'), 'the office is still available for assignments and capacity editing');
    }
}
