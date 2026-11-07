<?php

namespace App\Support\Marketing;

use App\Actions\Marketing\CreateFollowUpAction;
use App\Models\Admin;
use App\Models\FollowUp;
use App\Models\MarketingRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision E-10 (2026-11-06): when a Work Date is recorded on a PROJECT record, the follow-up for that date is created automatically.
 *
 * It is the ordinary follow-up (CreateFollowUpAction: status Scheduled, history row, audit entry, same assignee rule), made once per record and date: if
 * the record already has a follow-up on that date, in any state, nothing is added. Changing a Work Date adds a follow-up for the new date and leaves the
 * follow-up of the old date and its history exactly as they were.
 */
final class WorkDateFollowUp
{
    public const NOTE = 'Created automatically from the Work Date of the record.';

    public static function ensure(MarketingRecord $record, ?string $date, Admin $actor): ?FollowUp
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        $day = CarbonImmutable::parse($date)->toDateString();

        // The record row is locked while the check and the insert run, so two saves at the same moment cannot both find "no follow-up yet" and each add one.
        return DB::transaction(function () use ($record, $day, $actor): ?FollowUp {
            MarketingRecord::query()->whereKey($record->id)->lockForUpdate()->first();

            if (FollowUp::query()->where('marketing_record_id', $record->id)->whereDate('follow_up_date', $day)->exists()) {
                return null;
            }

            return app(CreateFollowUpAction::class)->handle($record, ['follow_up_date' => $day, 'note' => self::NOTE], $actor);
        });
    }
}
