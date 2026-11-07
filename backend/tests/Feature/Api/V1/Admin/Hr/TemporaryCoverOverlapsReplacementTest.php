<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\AuditLog;
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
 * Owner decision O-1 (2026-11-06): a NEW Temporary Replacement is refused when another Replacement already holds the SAME position on any overlapping day
 * (inclusive calendar days: new_start <= existing_end AND new_end >= existing_start). "Another Replacement" here is a Replacement ASSIGNMENT (the Replace
 * Employee flow, type `replacement`, linked to the position by `replaces_assignment_id`); cover-versus-cover overlaps were already refused (U12, see
 * TemporaryCoverConflictTest). The rule holds on create, on editing a cover's dates, on extending a cover's last day, and - reversed - when a Replacement is
 * reopened. A refusal writes nothing. Synthetic data only, everything through the API.
 */
class TemporaryCoverOverlapsReplacementTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

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
    private function position(): array
    {
        $holder = $this->makeEmployee('active', 'permanent');

        return [$holder, $this->makeAssignment($holder, $this->makeCompany(fake()->unique()->company()), null, 300, '2026-01-01')];
    }

    /** A Replacement assignment of the position over the given days (end = null: still open). */
    private function replacement(EmployeeWorkAssignment $position, string $start, ?string $end, string $status = 'ended', string $type = 'replacement'): EmployeeWorkAssignment
    {
        return EmployeeWorkAssignment::query()->create([
            'employee_id' => $this->makeEmployee('active', 'permanent')->id, 'client_company_id' => $position->client_company_id, 'replaces_assignment_id' => $position->id,
            'assignment_type' => $type, 'start_date' => $start, 'end_date' => $end, 'salary_amount' => 300, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly', 'status' => $status,
        ]);
    }

    private function create(EmployeeWorkAssignment $position, string $start, ?string $end, ?Employee $temp = null): TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/v1/admin/hr/temporary-replacements', [
            'work_assignment_id' => $position->id, 'replacement_employee_id' => ($temp ?? $this->makeEmployee('active', 'temporary'))->id, 'start_date' => $start, 'end_date' => $end,
        ]);
    }

    private function edit(TemporaryReplacement $cover, array $payload): TestResponse
    {
        $version = $this->withToken($this->token)->getJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/change-preview")->assertOk()->json('data.version');

        return $this->withToken($this->token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}", [...$payload, 'version' => $version]);
    }

    /** @return array<string, mixed> everything a refusal must leave exactly as it was */
    private function state(EmployeeWorkAssignment ...$assignments): array
    {
        return [
            'covers' => TemporaryReplacement::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'assignments' => EmployeeWorkAssignment::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'employees' => Employee::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'audit' => AuditLog::query()->count(),
            'payroll_items' => PayrollItem::query()->count(),
        ];
    }

    // ------------------------------------------------------------------------------------------------ A, B, C: overlapping days are refused

    public function test_a_cover_sharing_any_day_with_a_replacement_of_the_same_position_is_refused_and_writes_nothing(): void
    {
        [, $position] = $this->position();
        $held = $this->replacement($position, '2026-11-01', '2026-11-10');

        $cases = [
            'A  overlapping (5-15 Nov)' => ['2026-11-05', '2026-11-15'],
            'B  exactly the same days (1-10 Nov)' => ['2026-11-01', '2026-11-10'],
            'C  ends on the first day (Oct 25 - 1 Nov)' => ['2026-10-25', '2026-11-01'],
            'D  starts on the last day (10-20 Nov)' => ['2026-11-10', '2026-11-20'],
            'E  inside (3-4 Nov)' => ['2026-11-03', '2026-11-04'],
            'F  surrounding (Oct 20 - 30 Nov)' => ['2026-10-26', '2026-11-30'],
            'G  open ended, starting inside' => ['2026-11-05', null],
        ];

        $temp = $this->makeEmployee('active', 'temporary');

        foreach ($cases as $name => [$start, $end]) {
            $before = $this->state();

            $response = $this->create($position, $start, $end, $temp)->assertStatus(422);
            $this->assertSame('TEMPORARY_COVER_OVERLAPS_REPLACEMENT', $response->json('error_code'), $name);
            $this->assertStringContainsString("Replacement #{$held->id}", $response->json('message'), $name);
            $this->assertSame(['replacement', $held->id, '2026-11-01', '2026-11-10'], [$response->json('errors.conflicts.0.kind'), $response->json('errors.conflicts.0.id'), $response->json('errors.conflicts.0.start_date'), $response->json('errors.conflicts.0.end_date')], $name);

            // H: no new cover, no assignment or employee change, no audit row, no payroll change (and the temporary person was not activated or re-typed).
            $this->assertSame($before, $this->state(), "{$name}: nothing was written");
        }

        $this->assertSame(0, TemporaryReplacement::query()->count());
    }

    // ------------------------------------------------------------------------------------------------ D, E: what must still be accepted

    public function test_adjacent_days_and_other_positions_are_not_affected(): void
    {
        [, $position] = $this->position();
        [, $other] = $this->position();
        $this->replacement($position, '2026-11-01', '2026-11-10');

        // D: the day after the Replacement's last day, and the day before its first day.
        $this->create($position, '2026-11-11', '2026-11-20')->assertCreated();
        $this->create($position, '2026-10-25', '2026-10-31')->assertCreated();
        // E: another position on the very same days.
        $this->create($other, '2026-11-01', '2026-11-10')->assertCreated();
        $this->create($other, '2026-11-05', '2026-11-15', null)->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_POSITION'); // the old cover-versus-cover rule is untouched

        $this->assertSame(3, TemporaryReplacement::query()->count());
    }

    public function test_an_open_ended_replacement_blocks_every_later_day_but_not_the_days_before_it_started(): void
    {
        [, $position] = $this->position();
        $this->replacement($position, '2026-11-01', null, 'active');

        $this->create($position, '2026-12-05', '2026-12-08')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_REPLACEMENT');
        $this->create($position, '2026-11-20', null)->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_REPLACEMENT');
        $this->create($position, '2026-10-25', '2026-10-31')->assertCreated();
    }

    public function test_a_cancelled_replacement_a_permanent_successor_and_a_replacement_of_another_position_do_not_block(): void
    {
        [, $position] = $this->position();
        [, $other] = $this->position();
        $this->replacement($position, '2026-11-01', '2026-11-10', 'cancelled');
        $this->replacement($position, '2026-11-12', '2026-11-14', 'ended', 'permanent'); // a successor, not a Replacement
        $this->replacement($other, '2026-11-01', '2026-11-30', 'active');

        $this->create($position, '2026-11-01', '2026-11-30')->assertCreated();
    }

    // ------------------------------------------------------------------------------------------------ F: editing

    public function test_editing_a_cover_into_a_replacements_days_is_refused_and_a_harmless_edit_is_not(): void
    {
        [, $position] = $this->position();
        $this->replacement($position, '2026-11-01', '2026-11-10');
        $cover = TemporaryReplacement::query()->findOrFail($this->create($position, '2026-11-11', '2026-11-20')->assertCreated()->json('data.id'));

        // Shortening, or moving within the free days, works: the cover is never compared with itself or with the Replacement it does not touch.
        $this->edit($cover, ['start_date' => '2026-11-12', 'end_date' => '2026-11-18', 'reason' => 'Annual leave', 'change_reason' => 'Moved inside the free days'])->assertOk();
        $this->assertSame(['2026-11-12', '2026-11-18'], [$cover->fresh()->start_date->toDateString(), $cover->fresh()->end_date->toDateString()]);

        // Moving it back over the Replacement's days is refused and nothing changes.
        $before = $this->state();
        $this->edit($cover, ['start_date' => '2026-11-05', 'end_date' => '2026-11-18', 'reason' => 'Annual leave', 'change_reason' => 'Trying to move it onto the Replacement'])->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_REPLACEMENT');
        $this->assertSame($before, $this->state());
    }

    public function test_extending_a_covers_last_day_into_a_replacements_days_is_refused(): void
    {
        [, $position] = $this->position();
        $this->replacement($position, '2026-11-20', '2026-11-30');
        $cover = TemporaryReplacement::query()->findOrFail($this->create($position, '2026-11-11', '2026-11-15')->assertCreated()->json('data.id'));

        $before = $this->state();
        $this->withToken($this->token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/end", ['end_date' => '2026-11-25', 'confirm_extension' => true])
            ->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_REPLACEMENT');
        $this->assertSame($before, $this->state());

        // Ending earlier, or extending up to the day before the Replacement starts, is allowed.
        $this->withToken($this->token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/end", ['end_date' => '2026-11-19', 'confirm_extension' => true])->assertOk();
    }

    public function test_the_preview_endpoints_say_the_same_thing_before_anything_is_saved(): void
    {
        [, $position] = $this->position();
        $held = $this->replacement($position, '2026-11-01', '2026-11-10');
        $temp = $this->makeEmployee('active', 'temporary');

        $check = $this->withToken($this->token)->getJson("/api/v1/admin/hr/temporary-replacements/conflict-check?work_assignment_id={$position->id}&replacement_employee_id={$temp->id}&start_date=2026-11-05&end_date=2026-11-08")->assertOk();
        $this->assertSame('TEMPORARY_COVER_OVERLAPS_REPLACEMENT', $check->json('data.conflict.code') ?? $check->json('data.code') ?? $check->json('data.conflict.error_code'));
        $this->assertSame(0, TemporaryReplacement::query()->count());
        $this->assertNotNull($held->fresh());
    }

    // ------------------------------------------------------------------------------------------------ G: reopening a Replacement

    public function test_reopening_an_ended_replacement_over_days_a_cover_now_has_is_refused_and_writes_nothing(): void
    {
        [, $position] = $this->position();
        $replacement = $this->replacement($position, '2026-11-01', '2026-11-10');
        $this->create($position, '2026-11-11', '2026-11-20')->assertCreated(); // allowed: the Replacement ended on the 10th

        $before = $this->state();
        $this->withToken($this->token)->postJson("/api/v1/admin/hr/client-companies/{$position->client_company_id}/employees/{$replacement->id}/reopen", ['reason' => 'It was ended by mistake'])
            ->assertStatus(422)->assertJsonPath('error_code', 'REPLACEMENT_OVERLAPS_TEMPORARY_COVER');

        $this->assertSame($before, $this->state());
        $this->assertSame('ended', $replacement->fresh()->status->value);
        $this->assertSame('2026-11-10', $replacement->fresh()->end_date->toDateString());
    }

    public function test_reopening_a_replacement_with_no_cover_in_the_way_still_works_and_a_permanent_assignment_is_unaffected(): void
    {
        [, $position] = $this->position();
        $replacement = $this->replacement($position, '2026-11-01', '2026-11-10');
        // A cover of ANOTHER position never matters.
        [, $other] = $this->position();
        $this->create($other, '2026-11-11', '2026-11-20')->assertCreated();

        $this->withToken($this->token)->postJson("/api/v1/admin/hr/client-companies/{$position->client_company_id}/employees/{$replacement->id}/reopen", ['reason' => 'It was ended by mistake'])->assertOk();
        $this->assertSame('active', $replacement->fresh()->status->value);
        $this->assertNull($replacement->fresh()->end_date);

        // A reopened Replacement now holds the position from its first day on, so the O-1 rule applies to it like to any other.
        $this->create($position, '2026-12-01', '2026-12-05')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_REPLACEMENT');
    }

    // ------------------------------------------------------------------------------------------------ the real lifecycle, through the Replace Employee flow

    public function test_after_replace_and_return_the_days_the_replacement_held_stay_closed_to_covers_and_the_days_after_the_return_are_open(): void
    {
        [, $position] = $this->position();
        $candidate = $this->makeEmployee('active', 'permanent');
        $company = $position->client_company_id;

        $replaced = $this->withToken($this->token)->postJson("/api/v1/admin/hr/client-companies/{$company}/employees/{$position->id}/replace", ['replacement_employee_id' => $candidate->id, 'replacement_date' => '2026-11-01', 'assignment_type' => 'replacement'])->assertOk();
        $replacementId = $replaced->json('data.replacement.id');

        // While the Replacement works (open) a cover is refused for any later day.
        $this->create($position, '2026-11-05', '2026-11-06')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_REPLACEMENT');

        // The holder returns on 11 Nov: the Replacement's last day is the 10th and stays recorded history.
        $this->withToken($this->token)->postJson("/api/v1/admin/hr/client-companies/{$company}/employees/{$replacementId}/return", ['return_date' => '2026-11-11', 'salary_amount' => 300, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'])->assertOk();
        $this->assertSame('2026-11-10', EmployeeWorkAssignment::query()->findOrFail($replacementId)->end_date->toDateString());

        $this->create($position, '2026-11-05', '2026-11-06')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_REPLACEMENT'); // history still holds those days
        $this->create($position, '2026-11-11', '2026-11-15')->assertCreated();                                                                                   // the day after it, the holder is back and a cover may be recorded

        // The existing refusal to reopen the Replacement of a finished cycle is unchanged.
        $this->withToken($this->token)->postJson("/api/v1/admin/hr/client-companies/{$company}/employees/{$replacementId}/reopen", ['reason' => 'Trying to reopen a finished cover'])
            ->assertStatus(422)->assertJsonPath('error_code', 'ASSIGNMENT_COVER_FINISHED');
    }

    // ------------------------------------------------------------------------------------------------ I: concurrency

    public function test_two_requests_for_the_same_position_cannot_both_win_the_position_row_is_locked_before_the_overlap_is_judged(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row locks are only visible on PostgreSQL; the real two-process race is run against the local PG server (see docs/PRE_PRODUCTION_FULL_WEB_PANEL_QA.md).');
        }

        [, $position] = $this->position();
        $this->replacement($position, '2026-11-01', '2026-11-10');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->create($position, '2026-11-05', '2026-11-06')->assertStatus(422);
        $log = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => strtolower($q));

        $lock = $log->search(fn ($q) => str_contains($q, 'from "employee_work_assignments"') && str_contains($q, 'for update'));
        $judge = $log->search(fn ($q) => str_contains($q, 'from "employee_work_assignments"') && str_contains($q, '"replaces_assignment_id"'));
        $this->assertNotFalse($lock, 'the position row is locked');
        $this->assertNotFalse($judge, 'the Replacement overlap is judged');
        $this->assertLessThan($judge, $lock, 'the lock comes first, so a concurrent request waits and then sees the other one');
    }

    public function test_two_attempts_one_after_the_other_only_the_first_can_exist(): void
    {
        [, $position] = $this->position();
        $temp = $this->makeEmployee('active', 'temporary');

        $this->create($position, '2026-11-05', '2026-11-15', $temp)->assertCreated();
        $this->create($position, '2026-11-10', '2026-11-20')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_POSITION');
        $this->replacement($position, '2026-11-25', '2026-11-30');
        $this->create($position, '2026-11-28', '2026-12-02')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_REPLACEMENT');

        $this->assertSame(1, TemporaryReplacement::query()->count());
    }
}
