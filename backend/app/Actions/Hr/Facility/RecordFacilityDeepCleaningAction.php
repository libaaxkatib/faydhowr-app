<?php

namespace App\Actions\Hr\Facility;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\FacilityDeepCleaning;
use App\Models\FacilityInspection;
use App\Support\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Records, corrects or withdraws the Deep Cleaning completion of one facility inspection. Called inside the inspection's own transaction, so a
 * refusal rolls the whole inspection back.
 *
 * What the input means (the `deep_cleaning` object of the inspection form; an inspection request WITHOUT that key never reaches this class):
 *
 *   completed = false, nothing recorded yet   -> nothing happens: "no Deep Cleaning recorded" is the absence of a row, never a completion.
 *   completed = true,  nothing recorded yet   -> a completion is recorded with its own date, the recording admin and the notes.
 *   completed = true,  same date and notes    -> nothing happens (a repeated submission cannot count twice or touch the history).
 *   completed = true,  a different date/notes -> a CORRECTION: needs a reason, keeps who corrected it, and the audit log keeps before and after.
 *   completed = false, a completion recorded  -> the completion is WITHDRAWN (kept, never counted again): needs a reason.
 *
 * The same facility cannot have two counted completions on the same day: a second submission or a second inspection naming that day is refused.
 */
class RecordFacilityDeepCleaningAction
{
    /** @param  array{completed: bool, completed_on?: ?string, notes?: ?string, change_reason?: ?string}  $input */
    public function handle(FacilityInspection $inspection, array $input, Admin $actor): ?FacilityDeepCleaning
    {
        $existing = FacilityDeepCleaning::query()->where('facility_inspection_id', $inspection->id)->first();
        $active = $existing !== null && $existing->voided_at === null ? $existing : null;
        $reason = $this->clean($input['change_reason'] ?? null);

        if (! filter_var($input['completed'], FILTER_VALIDATE_BOOLEAN)) {
            return $active === null ? $existing : $this->withdraw($inspection, $active, $reason, $actor);
        }

        $date = (string) $input['completed_on'];
        $notes = $this->clean($input['notes'] ?? null);

        $this->assertNotRecordedElsewhere($inspection, $date);

        if ($existing === null) {
            return $this->create($inspection, $date, $notes, $actor);
        }

        if ($active !== null && $active->completed_on->toDateString() === $date && $this->clean($active->notes) === $notes) {
            return $active;
        }

        return $this->correct($inspection, $existing, $date, $notes, $reason, $actor);
    }

    private function create(FacilityInspection $inspection, string $date, ?string $notes, Admin $actor): FacilityDeepCleaning
    {
        try {
            $row = FacilityDeepCleaning::query()->create([
                'facility_inspection_id' => $inspection->id,
                'client_company_id' => $inspection->client_company_id,
                'completed_on' => $date,
                'notes' => $notes,
                'recorded_by' => $actor->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->alreadyRecorded(null);
        }

        $this->audit(AuditAction::Create, $actor, $row, "Deep Cleaning recorded for facility inspection #{$inspection->id} (completed on {$date}).", ['completed_on' => $date, 'has_notes' => $notes !== null]);

        return $row;
    }

    private function correct(FacilityInspection $inspection, FacilityDeepCleaning $row, string $date, ?string $notes, ?string $reason, Admin $actor): FacilityDeepCleaning
    {
        $this->requireReason($reason, $row->voided_at === null
            ? 'A recorded Deep Cleaning can only be changed with a reason (at least 5 characters).'
            : 'A withdrawn Deep Cleaning can only be recorded again with a reason (at least 5 characters).');

        $before = ['completed_on' => $row->completed_on->toDateString(), 'notes_recorded' => $this->clean($row->notes) !== null, 'withdrawn' => $row->voided_at !== null];

        try {
            $row->update([
                'completed_on' => $date,
                'notes' => $notes,
                'changed_by' => $actor->id,
                'change_reason' => $reason,
                'voided_at' => null,
                'voided_by' => null,
                'void_reason' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->alreadyRecorded(null);
        }

        $this->audit(AuditAction::Update, $actor, $row, "Deep Cleaning of facility inspection #{$inspection->id} corrected.", [
            'before' => $before,
            'after' => ['completed_on' => $date, 'notes_recorded' => $notes !== null, 'withdrawn' => false],
            'reason' => $reason,
        ]);

        return $row;
    }

    private function withdraw(FacilityInspection $inspection, FacilityDeepCleaning $row, ?string $reason, Admin $actor): FacilityDeepCleaning
    {
        $this->requireReason($reason, 'A recorded Deep Cleaning can only be withdrawn with a reason (at least 5 characters).');

        $row->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);

        $this->audit(AuditAction::Update, $actor, $row, "Deep Cleaning of facility inspection #{$inspection->id} withdrawn.", [
            'before' => ['completed_on' => $row->completed_on->toDateString(), 'withdrawn' => false],
            'after' => ['withdrawn' => true],
            'reason' => $reason,
        ]);

        return $row;
    }

    /** Withdraws the completion an inspection recorded when that inspection itself is voided. Returns whether anything was withdrawn. */
    public function withdrawWithInspection(FacilityInspection $inspection, string $inspectionVoidReason, Admin $actor): bool
    {
        $row = FacilityDeepCleaning::query()->where('facility_inspection_id', $inspection->id)->counted()->first();

        if ($row === null) {
            return false;
        }

        $this->withdraw($inspection, $row, 'Inspection voided: '.$inspectionVoidReason, $actor);

        return true;
    }

    private function assertNotRecordedElsewhere(FacilityInspection $inspection, string $date): void
    {
        $other = FacilityDeepCleaning::query()->counted()
            ->where('client_company_id', $inspection->client_company_id)
            ->whereDate('completed_on', $date)
            ->where('facility_inspection_id', '!=', $inspection->id)
            ->first(['facility_inspection_id']);

        if ($other !== null) {
            throw $this->alreadyRecorded((int) $other->facility_inspection_id);
        }
    }

    private function alreadyRecorded(?int $inspectionId): HttpResponseException
    {
        $where = $inspectionId === null ? 'on another inspection' : "on inspection #{$inspectionId}";

        return new HttpResponseException(ApiResponse::error(
            "A Deep Cleaning of this company on that date is already recorded {$where}. Open that inspection instead of recording it again. Nothing was saved.",
            'DEEP_CLEANING_ALREADY_RECORDED',
            422,
        ));
    }

    private function requireReason(?string $reason, string $message): void
    {
        if ($reason === null || mb_strlen($reason) < 5) {
            throw new HttpResponseException(ApiResponse::error($message, 'DEEP_CLEANING_CHANGE_REASON_REQUIRED', 422, ['deep_cleaning.change_reason' => [$message]]));
        }
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    /** @param  array<string, mixed>  $metadata */
    private function audit(AuditAction $action, Admin $actor, FacilityDeepCleaning $row, string $description, array $metadata): void
    {
        event(AuditEvent::record(
            action: $action,
            admin: $actor,
            description: $description,
            entityType: FacilityDeepCleaning::class,
            entityId: $row->id,
            metadata: ['client_company_id' => $row->client_company_id, 'facility_inspection_id' => $row->facility_inspection_id, ...$metadata],
        ));
    }
}
