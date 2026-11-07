<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\AssignmentAbsence;
use App\Models\AssignmentCycleEvent;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision O-1, SYMMETRICAL half (2026-11-06): a Replacement may not hold a position over any day a Temporary cover of the SAME position already has,
 * whichever of the two was recorded first (the cover-first direction is TemporaryCoverOverlapsReplacementTest). Entry points: Replace Employee (create), a
 * Replacement's dates edited (PUT work-assignments), reopen, company reopen. A refusal is 422 REPLACEMENT_OVERLAPS_TEMPORARY_COVER naming the cover and writes
 * nothing. Old overlaps are never touched, and an edit that changes no date (notes) is not judged. Synthetic data only, everything through the API.
 */
class ReplacementOverlapsTemporaryCoverTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const CODE = 'REPLACEMENT_OVERLAPS_TEMPORARY_COVER';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-25 10:00:00');
        [, $this->token] = $this->superAdminToken();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Employee, 1: EmployeeWorkAssignment} a Permanent holder and the position he holds since January */
    private function position(?ClientCompany $company = null): array
    {
        $holder = $this->makeEmployee('active', 'permanent');

        return [$holder, $this->makeAssignment($holder, $company ?? $this->makeCompany(fake()->unique()->company()), null, 300, '2026-01-01')];
    }

    private function cover(EmployeeWorkAssignment $position, string $start, ?string $end): TemporaryReplacement
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/admin/hr/temporary-replacements', [
            'work_assignment_id' => $position->id, 'replacement_employee_id' => $this->makeEmployee('active', 'temporary')->id, 'start_date' => $start, 'end_date' => $end,
        ])->assertCreated();

        return TemporaryReplacement::query()->findOrFail($response->json('data.id'));
    }

    /** A cover that exists from before the rule (inserted directly: the API would refuse it now). */
    private function legacyCover(EmployeeWorkAssignment $position, string $start, ?string $end): TemporaryReplacement
    {
        return TemporaryReplacement::query()->create([
            'work_assignment_id' => $position->id, 'replacement_employee_id' => $this->makeEmployee('active', 'temporary')->id,
            'start_date' => $start, 'end_date' => $end, 'currency' => 'USD', 'status' => 'active',
        ]);
    }

    private function replacementRow(EmployeeWorkAssignment $position, string $start, ?string $end, string $status = 'ended'): EmployeeWorkAssignment
    {
        return EmployeeWorkAssignment::query()->create([
            'employee_id' => $this->makeEmployee('active', 'permanent')->id, 'client_company_id' => $position->client_company_id, 'replaces_assignment_id' => $position->id,
            'assignment_type' => 'replacement', 'start_date' => $start, 'end_date' => $end, 'salary_amount' => 300, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly', 'status' => $status,
        ]);
    }

    private function replace(EmployeeWorkAssignment $position, string $date, ?Employee $candidate = null): TestResponse
    {
        return $this->withToken($this->token)->postJson("/api/v1/admin/hr/client-companies/{$position->client_company_id}/employees/{$position->id}/replace", [
            'replacement_employee_id' => ($candidate ?? $this->makeEmployee('active', 'permanent'))->id, 'replacement_date' => $date, 'assignment_type' => 'replacement',
        ]);
    }

    private function editAssignment(EmployeeWorkAssignment $assignment, array $payload): TestResponse
    {
        return $this->withToken($this->token)->putJson("/api/v1/admin/hr/work-assignments/{$assignment->id}", $payload);
    }

    /** @return array<string, mixed> everything a refusal must leave exactly as it was */
    private function state(): array
    {
        return [
            'covers' => TemporaryReplacement::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'assignments' => EmployeeWorkAssignment::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'employees' => Employee::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'absences' => AssignmentAbsence::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'cycle_events' => AssignmentCycleEvent::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'audit' => AuditLog::query()->count(),
            'payroll_items' => PayrollItem::query()->count(),
        ];
    }

    // ------------------------------------------------------------------------------------------------ Replace Employee (create)

    public function test_replace_employee_is_refused_when_a_temporary_cover_holds_any_of_those_days_and_writes_nothing(): void
    {
        [, $position] = $this->position();
        $cover = $this->cover($position, '2026-11-05', '2026-11-15');
        $candidate = $this->makeEmployee('active', 'permanent');

        $cases = [
            'partial: the Replacement starts inside the cover' => '2026-11-10',
            'exact boundary: the Replacement starts on the cover\'s last day' => '2026-11-15',
            'exact boundary: the Replacement starts on the cover\'s first day' => '2026-11-05',
            'an open-ended Replacement that starts before the cover runs into it' => '2026-10-30',
        ];

        foreach ($cases as $name => $date) {
            $before = $this->state();

            $response = $this->replace($position, $date, $candidate)->assertStatus(422);
            $this->assertSame(self::CODE, $response->json('error_code'), $name);
            $this->assertSame(['cover', $cover->id, '2026-11-05', '2026-11-15'], [$response->json('errors.conflicts.0.kind'), $response->json('errors.conflicts.0.id'), $response->json('errors.conflicts.0.start_date'), $response->json('errors.conflicts.0.end_date')], $name);
            $this->assertStringContainsString("cover #{$cover->id}", $response->json('message'), $name);
            $this->assertSame($before, $this->state(), "{$name}: nothing was written (no absence, no cycle event, no new assignment, no audit row)");
        }

        // Adjacent: the day after the cover's last day is free.
        $this->replace($position, '2026-11-16', $candidate)->assertOk();
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('assignment_type', 'replacement')->count());
    }

    public function test_an_open_ended_cover_blocks_every_replacement_date_from_its_start_on_but_not_a_date_before_it_that_stays_clear_of_it(): void
    {
        [, $position] = $this->position();
        $this->cover($position, '2026-11-20', null);

        $this->replace($position, '2026-12-05')->assertStatus(422)->assertJsonPath('error_code', self::CODE)->assertJsonPath('errors.conflicts.0.end_date', null);
        // An open-ended Replacement that starts before the cover would run into it, so it is refused as well.
        $this->replace($position, '2026-11-10')->assertStatus(422)->assertJsonPath('error_code', self::CODE);
    }

    public function test_an_ended_cover_still_blocks_the_days_it_covered_but_not_the_days_after_it(): void
    {
        [, $position] = $this->position();
        $cover = $this->cover($position, '2026-11-05', null);
        $this->withToken($this->token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/end", ['end_date' => '2026-11-08'])->assertOk();

        $this->replace($position, '2026-11-06')->assertStatus(422)->assertJsonPath('error_code', self::CODE); // an open-ended Replacement from the 6th still reaches the 6th-8th
        $this->replace($position, '2026-11-09')->assertOk();
    }

    public function test_a_replacement_of_another_position_is_not_blocked_by_a_cover_of_this_one(): void
    {
        [, $position] = $this->position();
        [, $other] = $this->position();
        $this->cover($position, '2026-11-05', '2026-11-15');

        $this->replace($other, '2026-11-10')->assertOk();
        $this->assertSame(1, TemporaryReplacement::query()->count());
    }

    // ------------------------------------------------------------------------------------------------ editing and extending a Replacement

    public function test_editing_a_replacement_into_a_covers_days_is_refused_and_a_harmless_edit_is_not(): void
    {
        [, $position] = $this->position();
        $held = $this->replacementRow($position, '2026-11-01', '2026-11-03');
        $cover = $this->cover($position, '2026-11-05', '2026-11-15'); // allowed: the Replacement ended on the 3rd

        $before = $this->state();
        // Extension into the cover (partial), up to the cover's first day (boundary) and an exact copy of the cover's days are all refused.
        foreach (['partial extension' => ['end_date' => '2026-11-08'], 'ends on the cover\'s first day' => ['end_date' => '2026-11-05'], 'exactly the cover\'s days' => ['start_date' => '2026-11-05', 'end_date' => '2026-11-15'], 'surrounding the cover' => ['end_date' => '2026-11-30']] as $name => $payload) {
            $response = $this->editAssignment($held, $payload)->assertStatus(422);
            $this->assertSame(self::CODE, $response->json('error_code'), $name);
            $this->assertSame($cover->id, $response->json('errors.conflicts.0.id'), $name);
            $this->assertSame($before, $this->state(), "{$name}: nothing was written");
        }

        // Adjacent (the day before the cover) is allowed; shortening is allowed; the record never conflicts with itself.
        $this->editAssignment($held, ['end_date' => '2026-11-04'])->assertOk();
        $this->assertSame('2026-11-04', $held->fresh()->end_date->toDateString());
        $this->editAssignment($held, ['end_date' => '2026-11-02'])->assertOk();
        $this->assertSame(['2026-11-01', '2026-11-02'], [$held->fresh()->start_date->toDateString(), $held->fresh()->end_date->toDateString()]);
    }

    public function test_a_replacement_edit_that_changes_no_date_is_not_judged_even_next_to_an_old_overlap(): void
    {
        [, $position] = $this->position();
        $held = $this->replacementRow($position, '2026-11-01', '2026-11-10');
        $old = $this->legacyCover($position, '2026-11-05', '2026-11-08'); // an overlap from before the rule
        $oldBefore = $old->fresh()->getAttributes();
        $heldBefore = $held->fresh()->getAttributes();

        $this->editAssignment($held, ['notes' => 'Corrected spelling of the note'])->assertOk();
        $this->editAssignment($held, ['end_date' => '2026-11-10', 'notes' => 'Same last day sent again'])->assertOk();
        $this->assertSame($oldBefore, $old->fresh()->getAttributes(), 'the old cover was not touched');
        $this->assertSame('2026-11-10', $held->fresh()->end_date->toDateString());
        $this->assertSame('2026-11-01', $held->fresh()->start_date->toDateString());
        $this->assertNotSame($heldBefore['notes'] ?? null, $held->fresh()->notes);

        // A real change of dates is judged over the whole new period, so the old overlap blocks a date edit that keeps it.
        $this->editAssignment($held, ['end_date' => '2026-11-12'])->assertStatus(422)->assertJsonPath('error_code', self::CODE);
    }

    // ------------------------------------------------------------------------------------------------ reopening a Replacement

    public function test_reopening_a_replacement_cannot_create_a_conflicting_period_and_a_clean_reopen_still_works(): void
    {
        [, $position] = $this->position();
        $held = $this->replacementRow($position, '2026-11-01', '2026-11-03');
        $this->cover($position, '2026-11-10', '2026-11-12');
        $other = $this->replacementRow($position, '2026-12-01', '2026-12-02');

        $before = $this->state();
        $this->withToken($this->token)->postJson("/api/v1/admin/hr/client-companies/{$position->client_company_id}/employees/{$held->id}/reopen", ['reason' => 'It was ended by mistake'])
            ->assertStatus(422)->assertJsonPath('error_code', self::CODE);
        $this->assertSame($before, $this->state());
        $this->assertSame('ended', $held->fresh()->status->value);

        // Another position with no cover: a plain reopen is unaffected.
        [, $free] = $this->position();
        $clean = $this->replacementRow($free, '2026-11-01', '2026-11-03');
        $this->withToken($this->token)->postJson("/api/v1/admin/hr/client-companies/{$free->client_company_id}/employees/{$clean->id}/reopen", ['reason' => 'It was ended by mistake'])->assertOk();
        $this->assertNotNull($other->fresh());
    }

    public function test_the_finished_cycle_refusal_keeps_its_precedence_over_the_cover_refusal(): void
    {
        [, $position] = $this->position();
        $candidate = $this->makeEmployee('active', 'permanent');
        $company = $position->client_company_id;
        $replacementId = $this->replace($position, '2026-11-01', $candidate)->assertOk()->json('data.replacement.id');
        $this->withToken($this->token)->postJson("/api/v1/admin/hr/client-companies/{$company}/employees/{$replacementId}/return", ['return_date' => '2026-11-11', 'salary_amount' => 300, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'])->assertOk();
        $this->cover($position, '2026-11-20', '2026-11-25'); // the holder is back; a cover afterwards is fine

        $this->withToken($this->token)->postJson("/api/v1/admin/hr/client-companies/{$company}/employees/{$replacementId}/reopen", ['reason' => 'Trying to reopen a finished cover'])
            ->assertStatus(422)->assertJsonPath('error_code', 'ASSIGNMENT_COVER_FINISHED');
    }

    // ------------------------------------------------------------------------------------------------ company reopen (restore)

    public function test_reopening_a_company_does_not_give_a_replacement_back_days_a_cover_now_has(): void
    {
        $company = $this->makeCompany('Restore Co');
        [, $position] = $this->position($company);
        $replacement = $this->replacementRow($position, '2026-10-01', null, 'active');
        $hr = $this->token;

        $this->withToken($hr)->postJson("/api/v1/admin/hr/client-companies/{$company->id}/close", ['reason' => 'Closed for the test', 'effective_date' => '2026-10-15'])->assertCreated();
        $this->assertSame('2026-10-15', $replacement->fresh()->end_date->toDateString());
        // After the closure a cover is recorded for days the Replacement would get back (inserted directly: the closed position takes no new cover through the API).
        $cover = $this->legacyCover($position, '2026-10-20', '2026-10-22');
        $position->update(['end_date' => null, 'status' => 'active']); // the holder is restored first, as the reopening does (ordered by id)

        $row = collect($this->withToken($hr)->getJson("/api/v1/admin/hr/client-companies/{$company->id}/closure")->assertOk()->json('data.assignments'))->firstWhere('assignment_id', $replacement->id);
        $this->assertFalse($row['restorable']);
        $this->assertStringContainsString("cover #{$cover->id}", $row['conflict']);
    }

    // ------------------------------------------------------------------------------------------------ the cover side: metadata-only edits

    public function test_a_cover_that_already_overlaps_a_replacement_can_have_its_notes_corrected_but_not_its_dates_kept_or_widened(): void
    {
        [, $position] = $this->position();
        $this->replacementRow($position, '2026-11-01', '2026-11-10');
        $cover = $this->legacyCover($position, '2026-11-05', '2026-11-08');
        $datesBefore = [$cover->start_date->toDateString(), $cover->end_date->toDateString()];

        $version = fn (): string => $this->withToken($this->token)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/change-preview")->assertOk()->json('data.version');

        // Notes / reason only: allowed, audited, dates untouched.
        $auditBefore = AuditLog::query()->count();
        $this->withToken($this->token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}", ['reason' => 'Annual leave', 'notes' => 'Corrected note', 'change_reason' => 'Fixing a typo in the note', 'version' => $version()])->assertOk();
        $this->assertSame('Corrected note', $cover->fresh()->notes);
        $this->assertSame($datesBefore, [$cover->fresh()->start_date->toDateString(), $cover->fresh()->end_date->toDateString()]);
        $this->assertGreaterThan($auditBefore, AuditLog::query()->count(), 'the correction is audited');

        // Any change of dates is judged in full, so a date edit that keeps the overlap is refused and changes nothing.
        $this->withToken($this->token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}", ['start_date' => '2026-11-05', 'end_date' => '2026-11-09', 'reason' => 'Annual leave', 'change_reason' => 'Trying to widen the overlap', 'version' => $version()])
            ->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_REPLACEMENT');
        $this->assertSame($datesBefore, [$cover->fresh()->start_date->toDateString(), $cover->fresh()->end_date->toDateString()]);

        // Moving it clear of the Replacement repairs the old overlap.
        $this->withToken($this->token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}", ['start_date' => '2026-11-11', 'end_date' => '2026-11-12', 'reason' => 'Annual leave', 'change_reason' => 'Moved after the Replacement ended', 'version' => $version()])->assertOk();
    }

    // ------------------------------------------------------------------------------------------------ locking

    public function test_reopening_a_replacement_locks_its_row_and_the_position_row_before_the_covers_are_judged(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row locks are only visible on PostgreSQL; the real two-process race is run against the local PG server (see docs/PRE_PRODUCTION_FULL_WEB_PANEL_QA.md).');
        }

        [, $position] = $this->position();
        $held = $this->replacementRow($position, '2026-11-01', '2026-11-03');
        $this->cover($position, '2026-11-10', '2026-11-12');

        $judgedAfterLock = function (callable $request): void {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $request();
            $log = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower($q));

            $lock = $log->search(fn ($q) => str_contains($q, 'from "employee_work_assignments"') && str_contains($q, 'for update'));
            $judge = $log->search(fn ($q) => str_contains($q, 'from "temporary_replacements"') && str_contains($q, '"work_assignment_id"') && str_contains($q, 'order by'));
            $this->assertNotFalse($lock, 'a position / assignment row is locked');
            $this->assertNotFalse($judge, 'the covers are judged');
            $this->assertLessThan($judge, $lock, 'the lock comes first, so a concurrent writer waits and then sees the other one');
        };

        // Reopen has no earlier read-only pre-check, so this is the locked judgement itself (Replace Employee and the date edit repeat the same locked judgement after a cheap pre-check).
        $judgedAfterLock(fn () => $this->withToken($this->token)->postJson("/api/v1/admin/hr/client-companies/{$position->client_company_id}/employees/{$held->id}/reopen", ['reason' => 'It was ended by mistake'])->assertStatus(422)->assertJsonPath('error_code', self::CODE));
    }

    public function test_two_attempts_one_after_the_other_only_the_first_can_exist(): void
    {
        [, $position] = $this->position();

        $this->replace($position, '2026-11-01')->assertOk();
        // The Replacement now holds every day from the 1st on, so a cover on any later day is refused (cover-first direction) ...
        $this->withToken($this->token)->postJson('/api/v1/admin/hr/temporary-replacements', [
            'work_assignment_id' => $position->id, 'replacement_employee_id' => $this->makeEmployee('active', 'temporary')->id, 'start_date' => '2026-11-05', 'end_date' => '2026-11-06',
        ])->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_REPLACEMENT');
        $this->assertSame(0, TemporaryReplacement::query()->count());
    }
}
