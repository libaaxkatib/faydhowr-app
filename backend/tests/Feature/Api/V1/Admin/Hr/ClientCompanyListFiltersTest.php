<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\ClientCompany;
use App\Models\ClientCompanyAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Companies & Workplaces: server-side search, filters (category, status, workplaces), paging and counts. Every filter applies together with the
 * others; a call without parameters still returns every company because other screens use it for dropdowns.
 */
class ClientCompanyListFiltersTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/hr/client-companies';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);

        $alpha = $this->makeCompany('Alpha Hospital');
        $alpha->update(['contact_person' => 'Amina Warsame', 'phone' => '0610000111', 'location' => 'Hodan District']);
        ClientCompanyAlias::query()->create(['client_company_id' => $alpha->id, 'alias' => 'AL-PHA CLINIC', 'source' => 'import']);
        $this->makeClientLocation($alpha, 'Maternity Wing');

        $this->makeCompany('Beta School');
        $this->makeCompany('5G HOTEL');
        ClientCompany::query()->where('name', 'Beta School')->update(['status' => 'inactive']);
        $this->makeCompany('Gamma Household', 'guri');
        $this->makeCompany('Delta Household', 'guri');
        $this->makeClientLocation(ClientCompany::query()->where('name', 'Delta Household')->firstOrFail(), 'Second Floor');
    }

    private function list(array $query = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->getJson(self::URL.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /** @return list<string> */
    private function names(array $query): array
    {
        return array_column($this->list($query)->assertOk()->json('data'), 'name');
    }

    public function test_without_parameters_every_company_is_returned_as_before(): void
    {
        $response = $this->list()->assertOk();

        $this->assertCount(5, $response->json('data'));
        $this->assertNull($response->json('meta'), 'the dropdown call has no paging meta');
    }

    public function test_search_finds_a_company_by_name_in_any_case_and_by_part_of_it(): void
    {
        $this->assertSame(['Alpha Hospital'], $this->names(['search' => 'alpha']));
        $this->assertSame(['Alpha Hospital'], $this->names(['search' => 'HOSPITAL']));
        $this->assertSame(['Delta Household', 'Gamma Household'], $this->names(['search' => 'house']));
    }

    public function test_search_also_finds_another_name_of_the_company_contact_phone_location_and_workplace(): void
    {
        $this->assertSame(['Alpha Hospital'], $this->names(['search' => 'al-pha']), 'an alias');
        $this->assertSame(['Alpha Hospital'], $this->names(['search' => 'warsame']), 'the contact person');
        $this->assertSame(['Alpha Hospital'], $this->names(['search' => '0610000111']), 'the phone');
        $this->assertSame(['Alpha Hospital'], $this->names(['search' => 'hodan']), 'the location');
        $this->assertSame(['Alpha Hospital'], $this->names(['search' => 'maternity']), 'a workplace name');
        $this->assertSame(['Delta Household'], $this->names(['search' => 'second floor']), 'a workplace of a Guri');
    }

    public function test_search_ignores_spaces_in_a_name_and_treats_wildcard_characters_literally(): void
    {
        $this->assertSame(['5G HOTEL'], $this->names(['search' => '5ghotel']));
        $this->assertSame(['5G HOTEL'], $this->names(['search' => '  5g   hotel ']));
        $this->assertSame([], $this->names(['search' => '%']), 'a percent sign is not a wildcard');
        $this->assertSame([], $this->names(['search' => '_']), 'an underscore is not a wildcard');
    }

    public function test_category_and_status_filters_use_the_stored_values(): void
    {
        $this->assertSame(['Delta Household', 'Gamma Household'], $this->names(['category' => 'guri']));
        $this->assertSame(['5G HOTEL', 'Alpha Hospital', 'Beta School'], $this->names(['category' => 'company']));
        $this->assertSame(['Beta School'], $this->names(['status' => 'inactive']));
        $this->assertCount(4, $this->names(['status' => 'active']));
    }

    public function test_workplace_filter_separates_companies_with_and_without_a_workplace(): void
    {
        $this->assertSame(['Alpha Hospital', 'Delta Household'], $this->names(['workplaces' => 'with']));
        $this->assertSame(['5G HOTEL', 'Beta School', 'Gamma Household'], $this->names(['workplaces' => 'without']));
    }

    public function test_filters_combine_instead_of_overwriting_each_other(): void
    {
        $this->assertSame(['Delta Household'], $this->names(['category' => 'guri', 'workplaces' => 'with']));
        $this->assertSame(['Delta Household'], $this->names(['category' => 'guri', 'search' => 'delta', 'status' => 'active']));
        $this->assertSame([], $this->names(['category' => 'company', 'search' => 'delta']), 'a Guri is not found when only companies are asked for');
        $this->assertSame([], $this->names(['category' => 'company', 'status' => 'inactive', 'workplaces' => 'with']));
        $this->assertSame(['Beta School'], $this->names(['category' => 'company', 'status' => 'inactive', 'workplaces' => 'without']));
    }

    public function test_paging_keeps_the_filters_and_reports_counts_and_totals(): void
    {
        $page1 = $this->list(['category' => 'company', 'per_page' => 2, 'page' => 1])->assertOk();
        $page2 = $this->list(['category' => 'company', 'per_page' => 2, 'page' => 2])->assertOk();

        $this->assertSame(['5G HOTEL', 'Alpha Hospital'], array_column($page1->json('data'), 'name'));
        $this->assertSame(['Beta School'], array_column($page2->json('data'), 'name'));
        $this->assertSame([3, 2, 2], [$page1->json('meta.total'), $page1->json('meta.last_page'), $page2->json('meta.current_page')]);
        $this->assertSame(5, $page1->json('meta.unfiltered_total'));
        $this->assertSame(['all' => 5, 'company' => 3, 'guri' => 2], $page1->json('meta.counts'), 'the category buttons count everything except the category filter itself');
    }

    public function test_category_counts_follow_the_search_and_status_but_not_the_category(): void
    {
        $meta = $this->list(['search' => 'household', 'category' => 'company', 'page' => 1])->assertOk()->json('meta');

        $this->assertSame(0, $meta['total'], 'no company has "household" in its name');
        $this->assertSame(['all' => 2, 'company' => 0, 'guri' => 2], $meta['counts'], 'the Guri button still says how many Guri match, so the empty result can be explained');
        $this->assertSame(5, $meta['unfiltered_total']);
    }

    public function test_an_empty_result_is_a_normal_empty_page_and_a_bad_value_is_refused(): void
    {
        $this->assertSame([], $this->names(['search' => 'no such company anywhere']));
        $this->list(['category' => 'hospital'])->assertUnprocessable();
        $this->list(['status' => 'deleted'])->assertUnprocessable();
        $this->list(['workplaces' => 'maybe'])->assertUnprocessable();
        $this->list(['per_page' => 500])->assertUnprocessable();
        $this->list(['page' => 0])->assertUnprocessable();
    }

    public function test_the_list_keeps_its_permission_rules_and_never_changes_data(): void
    {
        $before = ClientCompany::query()->orderBy('id')->get(['id', 'name', 'category', 'status', 'updated_at'])->toArray();

        $this->list(['search' => 'alpha', 'page' => 1])->assertOk();

        $this->assertSame($before, ClientCompany::query()->orderBy('id')->get(['id', 'name', 'category', 'status', 'updated_at'])->toArray());

        $marketing = $this->tokenForRoleWith(AdminRole::MarketingManager, ['marketing.view', 'marketing.manage']);
        $this->list(['search' => 'alpha'], $marketing)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson(self::URL)->assertUnauthorized();
    }
}
