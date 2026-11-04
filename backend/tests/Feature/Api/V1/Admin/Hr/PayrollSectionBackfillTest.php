<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Models\WorkLocation;
use App\Support\Hr\Payroll\PayrollSectionBackfill;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The company-sections migration links the EXISTING lines of draft / reviewed runs to their company sections without recalculating anything: only
 * payroll_items.section_id is written, every amount and id stays exactly as it was, and it is safe to run again. A month is built the way the
 * October draft is: generated before sections existed (here: generated, then its sections removed and its lines unlinked). Synthetic data only.
 */
class PayrollSectionBackfillTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $super;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-12-20 10:00:00');
        [, $this->super] = $this->superAdminToken();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** A month as it was before company sections: lines exist, no section does. @return int run id */
    private function legacyDraft(string $period = '2026-10'): int
    {
        $id = (int) $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated()->json('data.id');
        DB::table('payroll_items')->where('payroll_run_id', $id)->update(['section_id' => null]);
        DB::table('payroll_run_sections')->where('payroll_run_id', $id)->delete();

        return $id;
    }

    /** @return array<int, array<string, mixed>> every column that must never change, by line id, with the timestamps */
    private function lines(int $runId): array
    {
        $rows = DB::table('payroll_items')->where('payroll_run_id', $runId)->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();

        return array_column(array_map(function (array $row): array {
            unset($row['section_id']);

            return $row;
        }, $rows), null, 'id');
    }

    /** Alpha (a1 300, a2 200), Bravo (b1 400) and an office employee with no company (o1 120). */
    private function world(): void
    {
        $alpha = $this->makeCompany('Alpha Co');
        $bravo = $this->makeCompany('Bravo Co');
        $this->makeAssignment($this->makeEmployee(), $alpha, null, 300);
        $this->makeAssignment($this->makeEmployee(), $alpha, null, 200);
        $this->makeAssignment($this->makeEmployee(), $bravo, null, 400);
        $office = WorkLocation::query()->where('location_type', 'office')->firstOrFail();
        $this->makeAssignment($this->makeEmployee(), null, $office, 120);
    }

    public function test_the_existing_lines_are_linked_to_their_company_without_changing_a_single_value_or_id(): void
    {
        $this->world();
        $runId = $this->legacyDraft();
        $before = $this->lines($runId);
        $this->assertSame(0, PayrollRunSection::query()->count());
        $this->assertCount(4, $before);

        $report = PayrollSectionBackfill::run();

        $this->assertSame(['runs' => 1, 'sections_created' => 3, 'lines_linked' => 4, 'lines_already_linked' => 0, 'lines_to_no_company_section' => 1, 'unresolved' => []], $report);
        $this->assertSame($before, $this->lines($runId), 'every column of every line, the ids and the timestamps are exactly as they were');
        $this->assertSame(0, PayrollItem::query()->whereNull('section_id')->count());

        $sections = PayrollRunSection::query()->with('clientCompany')->get()->keyBy(fn ($s) => $s->clientCompany?->name ?? 'no company');
        $this->assertSame(['Alpha Co', 'Bravo Co', 'no company'], $sections->keys()->sort()->values()->all());
        $this->assertSame([50000, 40000, 12000], [$sections['Alpha Co']->totals['net_cents'], $sections['Bravo Co']->totals['net_cents'], $sections['no company']->totals['net_cents']]);
        $this->assertSame(['draft'], $sections->pluck('status')->unique()->values()->all());
        $this->assertSame('draft', PayrollRun::query()->findOrFail($runId)->status, 'the run itself is not changed');

        // Each line sits in the section of the company its ASSIGNMENT belongs to (not a text label).
        foreach (PayrollItem::query()->with('section.clientCompany', 'workAssignment')->get() as $item) {
            $this->assertSame($item->workAssignment->client_company_id, $item->section->client_company_id);
        }
    }

    public function test_it_is_idempotent_and_a_second_run_changes_nothing(): void
    {
        $this->world();
        $runId = $this->legacyDraft();
        PayrollSectionBackfill::run();
        $after = $this->lines($runId);
        $sections = PayrollRunSection::query()->orderBy('id')->get(['id', 'client_company_id', 'status', 'totals'])->toArray();
        $links = DB::table('payroll_items')->orderBy('id')->pluck('section_id', 'id')->all();

        $second = PayrollSectionBackfill::run();

        $this->assertSame(['runs' => 1, 'sections_created' => 0, 'lines_linked' => 0, 'lines_already_linked' => 4, 'lines_to_no_company_section' => 0, 'unresolved' => []], $second);
        $this->assertSame($after, $this->lines($runId));
        $this->assertSame($sections, PayrollRunSection::query()->orderBy('id')->get(['id', 'client_company_id', 'status', 'totals'])->toArray());
        $this->assertSame($links, DB::table('payroll_items')->orderBy('id')->pluck('section_id', 'id')->all());
    }

    public function test_a_dry_run_reports_and_writes_nothing(): void
    {
        $this->world();
        $runId = $this->legacyDraft();
        $before = $this->lines($runId);

        $report = PayrollSectionBackfill::run(true);

        $this->assertSame([4, 0], [$report['lines_linked'], $report['sections_created']]);
        $this->assertSame(0, PayrollRunSection::query()->count());
        $this->assertSame(4, PayrollItem::query()->whereNull('section_id')->count());
        $this->assertSame($before, $this->lines($runId));
        $this->artisan('hr:link-payroll-sections', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, PayrollRunSection::query()->count());
    }

    public function test_a_reviewed_run_gives_reviewed_sections_and_an_approved_or_finalized_run_gives_sections_that_mirror_it(): void
    {
        $this->world();
        $reviewed = $this->legacyDraft('2026-10');
        $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-runs/{$reviewed}/review")->assertOk();
        DB::table('payroll_items')->where('payroll_run_id', $reviewed)->update(['section_id' => null]);
        DB::table('payroll_run_sections')->where('payroll_run_id', $reviewed)->delete();
        PayrollRun::query()->findOrFail($reviewed)->update(['status' => 'reviewed']);

        $approved = $this->legacyDraft('2026-11');
        PayrollRun::query()->findOrFail($approved)->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $approver = Admin::factory()->create()->id]);
        $approvedLines = $this->lines($approved);
        $approvedRun = PayrollRun::query()->findOrFail($approved)->getAttributes();

        $report = PayrollSectionBackfill::run();

        $this->assertSame(2, $report['runs'], 'the reviewed and the approved run were looked at');
        $this->assertSame(['reviewed'], PayrollRunSection::query()->where('payroll_run_id', $reviewed)->pluck('status')->unique()->values()->all());
        $this->assertNotNull(PayrollRunSection::query()->where('payroll_run_id', $reviewed)->value('reviewed_at'));
        // An approved run is linked too: its sections start approved, with the run's own approver and date, and the run row is never written.
        $this->assertSame(['approved'], PayrollRunSection::query()->where('payroll_run_id', $approved)->pluck('status')->unique()->values()->all());
        $this->assertSame([$approver], PayrollRunSection::query()->where('payroll_run_id', $approved)->pluck('approved_by')->unique()->values()->all());
        $this->assertSame($approvedLines, $this->lines($approved), 'no amount, deduction or id changes');
        $this->assertSame(0, PayrollItem::query()->where('payroll_run_id', $approved)->whereNull('section_id')->count());
        $this->assertSame($approvedRun, PayrollRun::query()->findOrFail($approved)->getAttributes(), 'the run itself is untouched');
    }

    public function test_a_month_linked_by_the_backfill_can_be_finalized_company_by_company_without_regenerating(): void
    {
        $this->world();
        $runId = $this->legacyDraft();
        $before = $this->lines($runId);
        PayrollSectionBackfill::run();
        $alpha = PayrollRunSection::query()->whereHas('clientCompany', fn ($q) => $q->where('name', 'Alpha Co'))->firstOrFail();

        foreach (['review', 'finalize'] as $step) {
            $this->as($this->super)->patchJson("/api/v1/admin/hr/payroll-run-sections/{$alpha->id}/{$step}")->assertOk();
        }

        $this->assertSame($before, $this->lines($runId), 'finalizing a company never touches a line');
        $this->assertSame(['finalized', 'draft', 'draft'], PayrollRunSection::query()->orderBy('id')->pluck('status')->all());
        $this->assertSame('draft', PayrollRun::query()->findOrFail($runId)->status);
        $this->assertSame(1, ClientCompany::query()->where('name', 'Alpha Co')->count());
    }
}
