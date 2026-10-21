<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\ClientCompanyAlias;
use App\Models\EmployeeWorkAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Correcting a company name ("5GHOTEL" -> "5G HOTEL") changes only the name: the id, the assignments, the history and the audit trail stay on the
 * same company, the old spelling becomes an alias so nothing that still uses it creates a duplicate, and a name that only differs by spaces or case
 * from another company (or from another company's alias) is refused.
 */
class CompanyRenameKeepsIdentityTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_a_rename_keeps_the_id_the_assignments_and_the_old_spelling_as_an_alias(): void
    {
        $company = $this->makeCompany('5GHOTEL');
        $employee = $this->makeEmployee();
        $assignment = $this->makeAssignment($employee, $company, null, 200, '2026-01-01');

        $this->as($this->hr)->putJson("/api/v1/admin/hr/client-companies/{$company->id}", ['name' => '5G HOTEL'])->assertOk()->assertJsonPath('data.name', '5G HOTEL')->assertJsonPath('data.id', $company->id);

        $this->assertSame(1, ClientCompany::query()->count(), 'no second company was created');
        $this->assertSame($company->id, $assignment->fresh()->client_company_id);
        $this->assertDatabaseHas('client_company_aliases', ['client_company_id' => $company->id, 'alias' => '5GHOTEL', 'source' => 'rename']);
        $log = AuditLog::query()->where('entity_type', ClientCompany::class)->where('entity_id', $company->id)->latest('id')->first();
        $this->assertSame(['renamed_from' => '5GHOTEL', 'renamed_to' => '5G HOTEL', 'old_name_kept_as_alias' => true], $log->metadata);
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('employee_id', $employee->id)->count());

        // Renaming back, or saving the same name again, adds no duplicate alias.
        $this->as($this->hr)->putJson("/api/v1/admin/hr/client-companies/{$company->id}", ['name' => '5G HOTEL', 'notes' => 'checked'])->assertOk();
        $this->assertSame(1, ClientCompanyAlias::query()->where('client_company_id', $company->id)->count());
    }

    public function test_spaces_inside_the_name_are_tidied_but_never_removed(): void
    {
        $company = $this->makeCompany('ABAALHOSPITAL');

        $this->as($this->hr)->putJson("/api/v1/admin/hr/client-companies/{$company->id}", ['name' => '  ABAAL    HOSPITAL '])->assertOk()->assertJsonPath('data.name', 'ABAAL HOSPITAL');
    }

    public function test_a_name_that_differs_only_by_spaces_or_case_from_another_company_is_refused(): void
    {
        $this->makeCompany('5G HOTEL');
        $other = $this->makeCompany('Different Place');

        foreach (['5GHOTEL', '5g  hotel', '5G Hotel', ' 5ghotel '] as $clash) {
            $this->as($this->hr)->putJson("/api/v1/admin/hr/client-companies/{$other->id}", ['name' => $clash])->assertUnprocessable()->assertJsonValidationErrors(['name'], 'errors');
            $this->as($this->hr)->postJson('/api/v1/admin/hr/client-companies', ['name' => $clash, 'category' => 'company'])->assertUnprocessable();
        }
        $this->assertSame('Different Place', $other->fresh()->name);
    }

    public function test_a_name_that_is_another_companys_old_spelling_is_refused_but_a_companys_own_old_spelling_is_allowed(): void
    {
        $mine = $this->makeCompany('Alpha Hospital');
        $theirs = $this->makeCompany('Beta School');
        ClientCompanyAlias::query()->create(['client_company_id' => $theirs->id, 'alias' => 'BETASCHOOL', 'source' => 'rename']);

        $this->as($this->hr)->putJson("/api/v1/admin/hr/client-companies/{$mine->id}", ['name' => 'Beta School Old'])->assertOk();
        $this->as($this->hr)->putJson("/api/v1/admin/hr/client-companies/{$mine->id}", ['name' => 'BETA SCHOOL'])->assertUnprocessable();
        $this->as($this->hr)->putJson("/api/v1/admin/hr/client-companies/{$mine->id}", ['name' => 'betaschool'])->assertUnprocessable();
        // The owner of an alias may use it again as its own name.
        $this->as($this->hr)->putJson("/api/v1/admin/hr/client-companies/{$theirs->id}", ['name' => 'BETASCHOOL'])->assertOk();
    }
}
