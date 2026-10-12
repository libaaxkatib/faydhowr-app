<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\ClientCompany;
use App\Models\ClientCompanyAlias;
use App\Models\EmployeeWorkAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\Support\ImportFixtures;
use Tests\TestCase;

/**
 * Phase B: the company import (plan format: name, category, aliases).
 */
class ImportCompaniesTest extends TestCase
{
    use HrFixtures;
    use ImportFixtures;
    use RefreshDatabase;

    private const HEADER = ['name', 'category', 'aliases'];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = $this->importSuperAdmin();
    }

    public function test_a_hundred_planned_companies_are_all_imported_including_the_unused_ones(): void
    {
        // 94 "used" companies plus 6 that no employee is assigned to yet: nothing is dropped for being unused.
        $rows = array_map(fn (int $i): array => ["Planned Company {$i}", $i % 5 === 0 ? 'guri' : 'company', $i <= 6 ? "Spelling {$i}A @ rows 10,11|Spelling {$i}B" : ''], range(1, 100));

        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $rows));
        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 100)->assertJsonPath('data.counts.skipped', 0)->assertJsonPath('data.counts.failed', 0);

        $this->assertSame(100, ClientCompany::query()->count());
        $this->assertSame(12, ClientCompanyAlias::query()->count());
        $this->assertSame(0, EmployeeWorkAssignment::query()->count(), 'Importing companies assigns nobody.');
    }

    public function test_aliases_are_stored_with_their_source_reference_and_the_import_id(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [['MarwaFertility', 'company', 'Marwa Fertility @ rows 170,171|MARWA FERTILITY CENTRE']]));
        $this->runImport($this->token, $id);

        $company = ClientCompany::query()->where('name', 'MarwaFertility')->firstOrFail();
        $aliases = $company->aliases()->orderBy('id')->get();
        $this->assertSame(['Marwa Fertility', 'MARWA FERTILITY CENTRE'], $aliases->pluck('alias')->all());
        $this->assertSame('rows 170,171', $aliases[0]->source_reference);
        $this->assertSame("Import #{$id}", $aliases[0]->source);
    }

    public function test_a_company_is_matched_by_name_ignoring_case_spacing_and_punctuation(): void
    {
        ClientCompany::query()->create(['name' => 'Hotel Transit', 'category' => 'company', 'status' => 'active']);

        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [['hotel-transit', 'company', ''], ['HOTEL   TRANSIT.', 'guri', '']]));
        $response = $this->as($this->token)->getJson(self::IMPORTS."/{$id}")->assertOk();

        $response->assertJsonPath('data.counts.duplicate', 2)->assertJsonPath('data.counts.valid', 0);
    }

    public function test_a_company_known_under_an_approved_alias_is_a_duplicate_not_a_new_company(): void
    {
        $existing = ClientCompany::query()->create(['name' => 'Marwa Fertility', 'category' => 'company', 'status' => 'active']);
        ClientCompanyAlias::query()->create(['client_company_id' => $existing->id, 'alias' => 'MarwaFertility', 'source' => 'test']);

        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [['MARWA FERTILITY', 'company', ''], ['marwafertility', 'company', '']]));
        $rows = $this->as($this->token)->getJson(self::IMPORTS."/{$id}/rows")->assertOk()->json('data');

        $this->assertSame(['COMPANY_EXISTS', 'ALIAS_EXISTS'], array_column($rows, 'code'));
        $this->assertSame([$existing->id, $existing->id], array_column($rows, 'record_id'));
        $this->assertSame('duplicate', $rows[1]['status']);
    }

    public function test_a_name_that_matches_more_than_one_company_goes_to_manual_review(): void
    {
        $a = ClientCompany::query()->create(['name' => 'Alpha Foods', 'category' => 'company', 'status' => 'active']);
        $b = ClientCompany::query()->create(['name' => 'Beta Foods', 'category' => 'company', 'status' => 'active']);
        ClientCompanyAlias::query()->create(['client_company_id' => $a->id, 'alias' => 'The Foods', 'source' => 'test']);
        ClientCompanyAlias::query()->create(['client_company_id' => $b->id, 'alias' => 'The Foods', 'source' => 'test']);

        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [['The Foods', 'company', '']]));
        $row = $this->as($this->token)->getJson(self::IMPORTS."/{$id}/rows")->json('data.0');

        $this->assertSame('review', $row['status']);
        $this->assertSame('AMBIGUOUS_MATCH', $row['code']);

        // Review rows are never imported.
        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 0);
        $this->assertSame(2, ClientCompany::query()->count());
    }

    public function test_an_alias_that_belongs_to_a_different_existing_company_goes_to_manual_review(): void
    {
        $existing = ClientCompany::query()->create(['name' => 'Existing Hospital', 'category' => 'company', 'status' => 'active']);

        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [['Brand New Clinic', 'company', 'Existing Hospital']]));
        $row = $this->as($this->token)->getJson(self::IMPORTS."/{$id}/rows")->json('data.0');

        $this->assertSame('review', $row['status']);
        $this->assertSame('ALIAS_BELONGS_TO_OTHER_COMPANY', $row['code']);
        $this->assertSame([$existing->id], $row['meta']['company_ids']);
    }

    public function test_a_spelling_shared_by_two_new_companies_in_one_file_goes_to_manual_review(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [['First New Co', 'company', 'Shared Name'], ['Second New Co', 'company', 'Shared Name'], ['Third Co', 'company', 'First New Co']]));
        $rows = $this->as($this->token)->getJson(self::IMPORTS."/{$id}/rows")->json('data');

        $this->assertSame(['valid', 'review', 'review'], array_column($rows, 'status'));
        $this->assertSame(['ALIAS_SHARED_IN_FILE', 'ALIAS_SHARED_IN_FILE'], [$rows[1]['code'], $rows[2]['code']]);
    }

    public function test_the_same_company_twice_in_one_file_is_imported_once(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [['Twice Co', 'company', ''], ['TWICE  CO', 'company', '']]));
        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 1)->assertJsonPath('data.counts.duplicate', 1);

        $this->assertSame(1, ClientCompany::query()->count());
    }

    public function test_an_alias_that_collides_with_another_company_after_the_preview_is_not_attached(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [['Newcomer Co', 'company', 'Taken Later|Free Spelling']]));
        $other = ClientCompany::query()->create(['name' => 'Taken Later', 'category' => 'company', 'status' => 'active']);

        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 1);

        $new = ClientCompany::query()->where('name', 'Newcomer Co')->firstOrFail();
        $this->assertSame(['Free Spelling'], $new->aliases()->pluck('alias')->all(), 'A spelling that now identifies another company is never attached.');
        $this->assertSame(0, $other->aliases()->count());
    }

    public function test_validation_rules_for_names_and_categories(): void
    {
        $rows = [['', 'company', ''], [str_repeat('x', 151), 'company', ''], ['Okay', 'hotel', ''], ['Okay Two', 'COMPANY', ''], ['Okay Three', ' Guri ', ''], ['Alias Long', 'company', str_repeat('y', 151)]];
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $rows));
        $codes = array_column($this->as($this->token)->getJson(self::IMPORTS."/{$id}/rows")->json('data'), 'code');

        $this->assertSame(['NAME_REQUIRED', 'NAME_TOO_LONG', 'CATEGORY_INVALID', null, null, 'ALIAS_TOO_LONG'], $codes);
    }

    public function test_importing_companies_writes_audit_entries_and_never_touches_employees(): void
    {
        $employee = $this->makeEmployee('active');
        $before = $employee->fresh()->toJson();

        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [['Audited Co', 'company', '']]));
        $this->runImport($this->token, $id);

        $this->assertDatabaseHas('audit_logs', ['description' => "Client company 'Audited Co' created."]);
        $this->assertSame($before, $employee->fresh()->toJson());
    }
}
