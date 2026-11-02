<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Actions\Hr\CreateWorkAssignmentAction;
use App\Models\Admin;
use App\Models\AssignmentAbsence;
use App\Models\AssignmentCycleEvent;
use App\Models\AssignmentSuccession;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Support\Hr\AbsenceCycles;
use App\Support\Hr\Payroll\PayrollRunCalculator;
use App\Support\Hr\PositionSuccession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * "Someone already takes over this assignment" must not fire for a Replacement of a FINISHED cover cycle. The 4SEASON RES case: Zakariye (#273, $130) was
 * away 12-16 October covered by Mohamed (Replacement, ended), came back on 17 October and worked to 25 October; from 26 October Mohamed takes the
 * position over as a Permanent employee. The finished cover is history (it keeps its cycle events and its payroll line); a Permanent successor already
 * recorded, a cover still working, a cover kept because the holder was ended while away, and any real overlap still block. Synthetic data only.
 */
class FinishedCoverCycleSuccessionTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $company;

    private Position $cleaner;

    private Admin $owner;

    private string $token;

    private Employee $zakariye;

    private Employee $mohamed;

    private EmployeeWorkAssignment $original;

    private EmployeeWorkAssignment $cover;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-02 12:00:00');
        $this->company = $this->makeCompany('4SEASON RES');
        $this->cleaner = Position::query()->create(['name' => 'Cleaner']);
        [$this->owner, $this->token] = $this->superAdminToken();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function as(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token);
    }

    private function url(string $tail, ?ClientCompany $company = null): string
    {
        return '/api/v1/admin/hr/client-companies/'.($company ?? $this->company)->id.'/'.$tail;
    }

    private function person(string $name): Employee
    {
        $e = $this->makeEmployee('active', 'permanent');
        $e->forceFill(['full_name' => $name, 'payment_account_number' => 'N-'.fake()->unique()->numerify('######')])->save();

        return $e;
    }

    private function permanentSuccessor(EmployeeWorkAssignment $of, Employee $by, string $lastDay = '2026-10-25', string $from = '2026-10-26', array $over = [])
    {
        return $this->as()->postJson($this->url("employees/{$of->id}/replace"), array_replace([
            'replacement_employee_id' => $by->id, 'replacement_date' => $from, 'original_last_day' => $lastDay, 'assignment_type' => 'permanent',
            'position_id' => $this->cleaner->id, 'salary_amount' => 130, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ], $over));
    }

    /**
     * The production history: original from 1 Oct; Mohamed covers 12-16 Oct as a Replacement; the original comes back on 17 Oct. The October payroll draft
     * is generated BEFORE the succession so that "the cover's payroll line stays untouched" can be checked.
     */
    private function finishedCycle(bool $withPayroll = true): void
    {
        $this->zakariye = $this->person('Zakariye Abdullahi Mohamud');
        $this->mohamed = $this->person('Mohamed Ahmed Suudi');
        $this->original = $this->makeAssignment($this->zakariye, $this->company, null, 130, '2026-10-01');
        $this->original->update(['position_id' => $this->cleaner->id]);

        $id = $this->as()->postJson($this->url("employees/{$this->original->id}/replace"), [
            'replacement_employee_id' => $this->mohamed->id, 'replacement_date' => '2026-10-12', 'original_last_day' => '2026-10-11',
            'assignment_type' => 'replacement', 'position_id' => $this->cleaner->id,
        ])->assertOk()->json('data.replacement.id');
        $this->cover = EmployeeWorkAssignment::query()->findOrFail($id);
        $this->as()->postJson($this->url("employees/{$id}/return"), ['return_date' => '2026-10-17', 'salary_amount' => 130, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'])->assertOk();

        if ($withPayroll) {
            $this->as()->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-10'])->assertSuccessful();
        }
    }

    /** @return array<string, mixed> */
    private function history(): array
    {
        return [
            'cover' => EmployeeWorkAssignment::query()->findOrFail($this->cover->id)->only(['employee_id', 'replaces_assignment_id', 'assignment_type', 'start_date', 'end_date', 'status', 'salary_amount', 'position_id', 'shift']),
            'absences' => AssignmentAbsence::query()->orderBy('id')->get()->toArray(),
            'events' => AssignmentCycleEvent::query()->orderBy('id')->get()->toArray(),
            'payroll_runs' => PayrollRun::query()->orderBy('id')->get()->toArray(),
            'payroll_items' => PayrollItem::query()->orderBy('id')->get()->toArray(),
        ];
    }

    private function codeOf(callable $call): ?string
    {
        try {
            $call();
        } catch (HttpResponseException $e) {
            return json_decode($e->getResponse()->getContent(), true)['error_code'] ?? null;
        }

        return null;
    }

    // ------------------------------------------------------------------ the exact scenario

    public function test_mohamed_becomes_permanent_from_26_october_after_zakariyes_finished_cover_cycle(): void
    {
        $this->finishedCycle();
        $before = $this->history();

        $response = $this->permanentSuccessor($this->original, $this->mohamed)->assertOk();

        $this->original->refresh();
        $this->assertSame('2026-10-25', $this->original->end_date->toDateString(), 'Zakariye worked through 25 October');
        $new = EmployeeWorkAssignment::query()->findOrFail($response->json('data.replacement.id'));
        $this->assertSame($this->mohamed->id, $new->employee_id);
        $this->assertSame('permanent', $new->assignment_type->value);
        $this->assertSame('2026-10-26', $new->start_date->toDateString());
        $this->assertSame($this->original->id, $new->replaces_assignment_id, 'it references Zakariye\'s assignment as its predecessor');
        $this->assertSame($this->cleaner->id, $new->position_id);
        $this->assertSame('130.00', (string) $new->salary_amount);
        $this->assertNotSame($this->cover->id, $new->id, 'a separate assignment: the Replacement row is never converted');

        $after = $this->history();
        $this->assertEquals($before['cover'], $after['cover'], 'the finished Replacement (12-16 Oct) is unchanged');
        $this->assertEquals($before['absences'], $after['absences'], 'the absence and the return are unchanged');
        $this->assertEquals($before['events'], $after['events'], 'its cover-cycle events are unchanged: nothing was added or removed');
        $this->assertEquals($before['payroll_runs'], $after['payroll_runs'], 'payroll was not regenerated, approved or changed');
        $this->assertEquals($before['payroll_items'], $after['payroll_items'], 'the Replacement\'s payroll line (and every other line) is exactly as it was');
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('replaces_assignment_id', $this->original->id)->where('assignment_type', 'permanent')->count());
    }

    public function test_october_pays_every_day_of_the_position_once_with_exact_cents_without_touching_payroll(): void
    {
        $this->finishedCycle();
        $this->permanentSuccessor($this->original, $this->mohamed)->assertOk();
        $stored = [PayrollRun::query()->orderBy('id')->get()->toArray(), PayrollItem::query()->orderBy('id')->get()->toArray()];

        $items = collect(app(PayrollRunCalculator::class)->calculate('2026-10', [$this->zakariye->id, $this->mohamed->id], false)['items']);

        $zakariye = $items->firstWhere('work_assignment_id', $this->original->id);
        $cover = $items->firstWhere('work_assignment_id', $this->cover->id);
        $permanent = $items->first(fn (array $i): bool => $i['employee_id'] === $this->mohamed->id && $i['work_assignment_id'] !== $this->cover->id);
        $this->assertSame([20, 5, 6], [$zakariye['eligible_days'], $cover['eligible_days'], $permanent['eligible_days']], '1-11 and 17-25 Oct, 12-16 Oct, 26-31 Oct');
        $this->assertSame(31, $zakariye['eligible_days'] + $cover['eligible_days'] + $permanent['eligible_days'], 'every calendar day is paid once');
        $this->assertSame(13000, $zakariye['gross_cents'] + $cover['gross_cents'] + $permanent['gross_cents'], 'the three lines add up to the $130.00 salary to the cent');
        $this->assertSame(13000, $zakariye['net_cents'] + $cover['net_cents'] + $permanent['net_cents']);

        $this->assertEquals($stored, [PayrollRun::query()->orderBy('id')->get()->toArray(), PayrollItem::query()->orderBy('id')->get()->toArray()], 'calculating changes nothing: payroll is only regenerated by the existing workflow');
    }

    // ------------------------------------------------------------------ the other workflows

    public function test_stop_employee_with_a_chosen_successor_works_after_a_finished_cycle(): void
    {
        $this->finishedCycle(false);

        $this->as()->postJson($this->url("employees/{$this->original->id}/stop"), [
            'end_date' => '2026-10-25', 'reason' => 'Left the position',
            'successor' => ['type' => 'person', 'employee_id' => $this->mohamed->id, 'salary_amount' => 130, 'salary_currency' => 'USD', 'effective_date' => '2026-10-26'],
        ])->assertOk();

        $new = EmployeeWorkAssignment::query()->where('replaces_assignment_id', $this->original->id)->where('assignment_type', 'permanent')->firstOrFail();
        $this->assertSame([$this->mohamed->id, '2026-10-26'], [$new->employee_id, $new->start_date->toDateString()]);
        $this->assertSame('2026-10-25', $this->original->fresh()->end_date->toDateString());
        $this->assertSame(['person'], AssignmentSuccession::query()->pluck('successor_type')->all());
    }

    public function test_editing_the_successors_start_date_is_revalidated_without_the_finished_cover_getting_in_the_way(): void
    {
        $this->finishedCycle(false);
        $id = $this->permanentSuccessor($this->original, $this->mohamed)->assertOk()->json('data.replacement.id');

        $this->as()->putJson("/api/v1/admin/hr/work-assignments/{$id}", ['start_date' => '2026-10-27'])->assertOk();
        $this->assertSame('2026-10-27', EmployeeWorkAssignment::query()->findOrFail($id)->start_date->toDateString());

        // The real protection stays: a start on or before Zakariye's last paid day is an overlap.
        $this->as()->putJson("/api/v1/admin/hr/work-assignments/{$id}", ['start_date' => '2026-10-25'])->assertStatus(422)->assertJsonPath('error_code', 'SUCCESSION_OVERLAP');
        $this->assertSame('2026-10-27', EmployeeWorkAssignment::query()->findOrFail($id)->start_date->toDateString());
    }

    public function test_the_recovery_center_no_longer_reports_the_finished_cover_as_a_successor(): void
    {
        $this->finishedCycle(false);
        $this->original->update(['end_date' => '2026-10-25', 'status' => 'ended']);

        $blockers = fn () => collect($this->as()->postJson('/api/v1/admin/recovery/preview', ['workflow' => 'reopen_assignment', 'assignment_id' => $this->original->id, 'params' => []])->assertOk()->json('data.blockers'))->pluck('code')->all();

        $codes = $blockers();
        $this->assertContains('USES_REPLACEMENT_CYCLE', $codes, 'the absence record still routes this to the cycle workflows');
        $this->assertNotContains('SUCCESSOR_EXISTS', $codes, 'Mohamed\'s finished Replacement is history, not someone who takes over');

        // A real Permanent successor is still reported.
        $successor = $this->makeAssignment($this->mohamed, $this->company, null, 130, '2026-10-26');
        $successor->update(['replaces_assignment_id' => $this->original->id, 'position_id' => $this->cleaner->id]);
        $this->assertContains('SUCCESSOR_EXISTS', $blockers());
    }

    public function test_the_recovery_center_date_correction_of_a_successor_is_not_blocked_by_the_finished_cover(): void
    {
        $this->finishedCycle(false);
        $this->original->update(['end_date' => '2026-10-25', 'status' => 'ended']);
        $successor = $this->makeAssignment($this->mohamed, $this->company, null, 130, '2026-10-26');
        $successor->update(['replaces_assignment_id' => $this->original->id, 'position_id' => $this->cleaner->id]);

        $preview = $this->as()->postJson('/api/v1/admin/recovery/preview', ['workflow' => 'correct_assignment_dates', 'assignment_id' => $successor->id, 'params' => ['month' => '2026-10', 'start_date' => '2026-10-28']])->assertOk()->json('data');
        $this->assertNotContains('SUCCESSION_CONFLICT', array_column($preview['blockers'], 'code'));

        $overlap = $this->as()->postJson('/api/v1/admin/recovery/preview', ['workflow' => 'correct_assignment_dates', 'assignment_id' => $successor->id, 'params' => ['month' => '2026-10', 'start_date' => '2026-10-24']])->assertOk()->json('data');
        $this->assertContains('SUCCESSION_CONFLICT', array_column($overlap['blockers'], 'code'), 'a start on or before the last paid day is still refused there');
    }

    // ------------------------------------------------------------------ what must keep blocking

    public function test_a_second_permanent_successor_is_still_refused(): void
    {
        $this->finishedCycle(false);
        $this->permanentSuccessor($this->original, $this->mohamed)->assertOk();
        $third = $this->person('Third Person');

        $this->assertSame('SUCCESSION_ALREADY_REPLACED', $this->codeOf(fn () => PositionSuccession::assertCanFollow($this->original->fresh(), $third->id, CarbonImmutable::parse('2026-10-27'))));
        $this->permanentSuccessor($this->original, $third, '2026-10-25', '2026-10-27')->assertStatus(422);
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('replaces_assignment_id', $this->original->id)->where('assignment_type', 'permanent')->count());
    }

    public function test_a_cover_kept_because_the_holder_was_ended_while_away_still_blocks(): void
    {
        $holder = $this->person('Holder');
        $position = $this->makeAssignment($holder, $this->company, null, 130, '2026-10-01');
        $position->update(['position_id' => $this->cleaner->id]);
        $coverId = $this->as()->postJson($this->url("employees/{$position->id}/replace"), ['replacement_employee_id' => $this->person('Cover')->id, 'replacement_date' => '2026-10-12', 'original_last_day' => '2026-10-11', 'assignment_type' => 'replacement', 'position_id' => $this->cleaner->id])->assertOk()->json('data.replacement.id');
        $this->as()->postJson($this->url("employees/{$position->id}/stop"), ['end_date' => '2026-10-20', 'reason' => 'Left while away', 'confirm_replacement_continues' => true])->assertOk();

        $absence = AssignmentAbsence::query()->where('work_assignment_id', $position->id)->firstOrFail();
        $this->assertNotNull($absence->terminated_on);
        $this->assertNull($absence->returned_on, 'the holder never returned: the cover keeps the position');
        $this->assertNotContains($coverId, AbsenceCycles::finishedCycleReplacementIds()->pluck('replacement_assignment_id')->all());
        $this->assertSame([$coverId], PositionSuccession::successorsOf($position->fresh())->pluck('id')->all());
        $this->assertSame('SUCCESSION_ALREADY_REPLACED', $this->codeOf(fn () => PositionSuccession::assertCanFollow($position->fresh(), $this->person('Other')->id, CarbonImmutable::parse('2026-10-25'))));
    }

    public function test_a_replacement_that_is_still_working_blocks_both_while_the_holder_is_away_and_after_the_end(): void
    {
        $holder = $this->person('Holder');
        $position = $this->makeAssignment($holder, $this->company, null, 130, '2026-10-01');
        $position->update(['position_id' => $this->cleaner->id]);
        $coverId = $this->as()->postJson($this->url("employees/{$position->id}/replace"), ['replacement_employee_id' => $this->person('Cover')->id, 'replacement_date' => '2026-10-12', 'original_last_day' => '2026-10-11', 'assignment_type' => 'replacement', 'position_id' => $this->cleaner->id])->assertOk()->json('data.replacement.id');

        // The holder is away and the cover is still working: it blocks another successor.
        $this->assertSame([$coverId], PositionSuccession::successorsOf($position->fresh(), holderAway: true)->pluck('id')->all());
        $this->assertSame('SUCCESSION_ALREADY_REPLACED', $this->codeOf(fn () => PositionSuccession::assertCanFollow($position->fresh(), $this->person('Other')->id, CarbonImmutable::parse('2026-10-25'))));

        // Even a Replacement of a cycle that is marked finished counts while it is still Active (inconsistent data is never ignored).
        AssignmentAbsence::query()->where('work_assignment_id', $position->id)->update(['returned_on' => '2026-10-17']);
        $this->assertContains($coverId, AbsenceCycles::finishedCycleReplacementIds()->pluck('replacement_assignment_id')->all());
        $position->update(['end_date' => '2026-10-25']);
        $this->assertSame([$coverId], PositionSuccession::successorsOf($position->fresh())->pluck('id')->all(), 'an Active Replacement still blocks');
        $this->assertSame(1, EmployeeWorkAssignment::query()->whereKey($coverId)->update(['status' => 'ended', 'end_date' => '2026-10-16']));
        $this->assertSame([], PositionSuccession::successorsOf($position->fresh())->pluck('id')->all(), 'once it has ended, a finished-cycle Replacement is history');
    }

    public function test_an_ended_replacement_with_no_cycle_record_is_not_assumed_to_be_history(): void
    {
        $holder = $this->person('Holder');
        $position = $this->makeAssignment($holder, $this->company, null, 130, '2026-10-01');
        $position->update(['end_date' => '2026-10-25', 'position_id' => $this->cleaner->id]);
        $legacy = $this->makeAssignment($this->person('Legacy Cover'), $this->company, null, 130, '2026-10-12');
        $legacy->update(['assignment_type' => 'replacement', 'replaces_assignment_id' => $position->id, 'end_date' => '2026-10-16', 'status' => 'ended', 'position_id' => $this->cleaner->id]);

        $this->assertSame('SUCCESSION_ALREADY_REPLACED', $this->codeOf(fn () => PositionSuccession::assertCanFollow($position->fresh(), $this->person('Next')->id, CarbonImmutable::parse('2026-10-26'))), 'only a cover recorded in a returned absence is history; nothing is ignored broadly');
    }

    public function test_dates_self_replacement_cancelled_predecessors_and_company_rules_still_apply(): void
    {
        $this->finishedCycle(false);

        // The start must be after the last paid day.
        $this->permanentSuccessor($this->original, $this->mohamed, '2026-10-25', '2026-10-25')->assertStatus(422);
        $this->permanentSuccessor($this->original, $this->mohamed, '2026-10-26', '2026-10-25')->assertStatus(422);
        $ended = $this->makeAssignment($this->person('Ended Holder'), $this->company, null, 130, '2026-10-01');
        $ended->update(['end_date' => '2026-10-25']);
        $this->assertSame('SUCCESSION_OVERLAP', $this->codeOf(fn () => PositionSuccession::assertCanFollow($ended->fresh(), $this->mohamed->id, CarbonImmutable::parse('2026-10-25'))), 'starting on the last paid day pays that day twice');
        $this->assertNull($this->codeOf(fn () => PositionSuccession::assertCanFollow($ended->fresh(), $this->mohamed->id, CarbonImmutable::parse('2026-10-26'))), 'the next day is allowed');
        // Nobody replaces themselves.
        $this->permanentSuccessor($this->original, $this->zakariye)->assertStatus(422)->assertJsonPath('error_code', 'REPLACEMENT_SAME_EMPLOYEE');
        $this->assertSame('SUCCESSION_SAME_EMPLOYEE', $this->codeOf(fn () => PositionSuccession::assertCanFollow($this->original, $this->zakariye->id, CarbonImmutable::parse('2026-10-26'))));
        // A salary is required for a Permanent successor.
        $this->permanentSuccessor($this->original, $this->mohamed, over: ['salary_amount' => null])->assertStatus(422);
        // A person who already holds an active assignment at this company without a workplace cannot be given a second one.
        $busy = $this->person('Already Here');
        $this->makeAssignment($busy, $this->company, null, 100, '2026-09-01');
        $this->permanentSuccessor($this->original, $busy)->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_AT_COMPANY');
        // A cancelled predecessor cannot be succeeded.
        $cancelled = $this->makeAssignment($this->person('Cancelled Holder'), $this->company, null, 130, '2026-10-01');
        $cancelled->update(['status' => 'cancelled', 'end_date' => '2026-10-05']);
        $this->assertSame('SUCCESSION_PREDECESSOR_CANCELLED', $this->codeOf(fn () => PositionSuccession::assertCanFollow($cancelled->fresh(), $this->mohamed->id, CarbonImmutable::parse('2026-10-26'))));
        // A predecessor of another company is refused when the successor is created.
        $other = $this->makeCompany('Other Company');
        $foreign = $this->makeAssignment($this->person('Foreign Holder'), $other, null, 130, '2026-10-01');
        $foreign->update(['end_date' => '2026-10-25']);
        $this->assertSame('SUCCESSION_DIFFERENT_COMPANY', $this->codeOf(fn () => app(CreateWorkAssignmentAction::class)->handle($this->mohamed, [
            'client_company_id' => $this->company->id, 'replaces_assignment_id' => $foreign->id, 'assignment_type' => 'permanent', 'start_date' => '2026-10-26',
            'salary_amount' => 130, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ], $this->owner)));

        $this->assertSame(0, EmployeeWorkAssignment::query()->where('replaces_assignment_id', $this->original->id)->where('assignment_type', 'permanent')->count(), 'every refusal left the position without a successor');
        $this->assertNull($this->original->fresh()->end_date, 'and Zakariye\'s assignment untouched');
    }
}
