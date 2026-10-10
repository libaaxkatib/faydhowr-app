<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\WorkforceRequest;
use App\Models\WorkLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The approved "work location is optional" rule also covers workforce
 * requests (a company asking for staff before the workplace is known).
 */
class OptionalLocationWorkforceRequestTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/hr/workforce-requests';

    private function body(array $extra = []): array
    {
        return array_merge(['quantity_needed' => 2, 'requested_date' => '2026-10-10'], $extra);
    }

    public function test_a_request_can_be_created_for_a_company_without_a_work_location(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Alpha Hospital');

        $this->withToken($token)->postJson(self::URL, $this->body(['client_company_id' => $company->id]))
            ->assertCreated()
            ->assertJsonPath('data.work_location_id', null)
            ->assertJsonPath('data.client_company_id', $company->id)
            ->assertJsonPath('data.client_company_name', 'Alpha Hospital');

        $this->assertDatabaseCount('work_locations', 1); // no placeholder invented
    }

    public function test_a_request_still_needs_a_company_or_a_location(): void
    {
        [, $token] = $this->superAdminToken();

        $this->withToken($token)->postJson(self::URL, $this->body())->assertStatus(422)->assertJsonValidationErrors('client_company_id');
    }

    public function test_a_known_location_supplies_the_company_and_contradictions_are_refused(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->makeCompany('Company A');
        $b = $this->makeCompany('Company B');
        $locationA = $this->makeClientLocation($a);
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();

        $this->withToken($token)->postJson(self::URL, $this->body(['work_location_id' => $locationA->id]))
            ->assertCreated()->assertJsonPath('data.client_company_id', $a->id);
        $this->withToken($token)->postJson(self::URL, $this->body(['work_location_id' => $locationA->id, 'client_company_id' => $b->id]))
            ->assertStatus(422)->assertJsonPath('error_code', 'LOCATION_COMPANY_MISMATCH');
        $this->withToken($token)->postJson(self::URL, $this->body(['work_location_id' => $office->id]))
            ->assertCreated()->assertJsonPath('data.client_company_id', null);
        $this->withToken($token)->postJson(self::URL, $this->body(['work_location_id' => $office->id, 'client_company_id' => $a->id]))
            ->assertStatus(422)->assertJsonPath('error_code', 'OFFICE_ASSIGNMENT_HAS_NO_COMPANY');
    }

    public function test_the_location_can_be_completed_later_but_not_swapped(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $first = $this->makeClientLocation($company, 'Site One');
        $second = $this->makeClientLocation($company, 'Site Two');
        $other = $this->makeClientLocation($this->makeCompany('Other Co'), 'Elsewhere');
        $id = $this->withToken($token)->postJson(self::URL, $this->body(['client_company_id' => $company->id]))->json('data.id');

        $this->withToken($token)->putJson(self::URL."/{$id}", ['work_location_id' => $other->id])
            ->assertStatus(422)->assertJsonPath('error_code', 'LOCATION_COMPANY_MISMATCH');
        $this->withToken($token)->putJson(self::URL."/{$id}", ['work_location_id' => $first->id])
            ->assertOk()->assertJsonPath('data.work_location_id', $first->id);
        $this->withToken($token)->putJson(self::URL."/{$id}", ['work_location_id' => $second->id])
            ->assertStatus(422)->assertJsonPath('error_code', 'REQUEST_LOCATION_ALREADY_SET');
        $this->assertSame($first->id, WorkforceRequest::query()->findOrFail($id)->work_location_id);
    }

    public function test_history_and_company_reports_include_requests_without_a_location(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->makeCompany('Company A');
        $b = $this->makeCompany('Company B');
        $this->withToken($token)->postJson(self::URL, $this->body(['client_company_id' => $a->id]))->assertCreated();
        $this->withToken($token)->postJson(self::URL, $this->body(['client_company_id' => $b->id]))->assertCreated();

        $this->withToken($token)->getJson("/api/v1/admin/hr/reports/workforce-requests?client_company_id={$a->id}")
            ->assertOk()->assertJsonCount(1, 'data');

        $summary = $this->withToken($token)->getJson('/api/v1/admin/hr/reports/summary')->assertOk();
        $byCompany = collect($summary->json('data.workforce_requests_by_company'))->pluck('total', 'name');
        $this->assertSame(1, (int) $byCompany['Company A']);
        $this->assertSame(1, (int) $byCompany['Company B']);
    }

    public function test_existing_location_based_requests_gain_their_company(): void
    {
        $company = $this->makeCompany();
        $location = $this->makeClientLocation($company);
        [, $token] = $this->superAdminToken();

        $id = $this->withToken($token)->postJson(self::URL, $this->body(['work_location_id' => $location->id]))->json('data.id');

        $this->assertSame($company->id, WorkforceRequest::query()->findOrFail($id)->client_company_id);
    }
}
