<?php

namespace App\Console\Commands;

use App\Enums\Marketing\FollowUpStatus;
use App\Events\Notification\NotificationRequested;
use App\Models\FollowUp;
use App\Support\Hr\Facility\FacilityCalendar;
use Illuminate\Console\Command;

/**
 * docs/HRM_MARKETING_SRS.md §14/§15: fires a reminder when a follow-up's
 * date arrives, to the assigned admin (falling back to the marketing
 * record's assigned admin). Reuses the entire existing notification
 * pipeline (NotificationChannelManager, templates, preferences, queued
 * delivery) — this command only decides WHEN to fire, never how delivery
 * happens.
 *
 * "Today" is the calendar day in Mogadishu (a follow-up date is a day the team picked there), not the UTC day. Running it again the same day is safe:
 * the event id of a reminder is follow-up id + day, and notifications are unique per recipient, channel and event id.
 */
class NotifyDueFollowUps extends Command
{
    protected $signature = 'follow-ups:notify-due
        {--dry-run : Only count the reminders that are due (nothing is sent)}';

    protected $description = 'Dispatch reminders for marketing follow-ups due today';

    public function handle(): int
    {
        $today = FacilityCalendar::today()->toDateString();

        $followUps = FollowUp::query()
            ->whereDate('follow_up_date', $today)
            ->whereIn('status', [FollowUpStatus::Scheduled->value, FollowUpStatus::Rescheduled->value])
            ->with(['assignedAdmin', 'marketingRecord'])
            ->get();

        $notified = 0;

        foreach ($followUps as $followUp) {
            $recipient = $followUp->assignedAdmin ?? $followUp->marketingRecord?->assignedAdmin;

            if ($recipient === null) {
                continue;
            }

            $notified++;

            if ($this->option('dry-run')) {
                continue;
            }

            event(NotificationRequested::make(
                recipient: $recipient,
                templateKey: 'follow_up_due_today',
                variables: [
                    'record_number' => $followUp->marketingRecord?->record_number,
                    'record_type' => $followUp->marketingRecord?->type?->value,
                ],
                eventId: 'follow-up-due-'.$followUp->id.'-'.$today,
            ));
        }

        $this->info($this->option('dry-run')
            ? "{$notified} follow-up reminder(s) are due today (nothing was sent)."
            : "Dispatched {$notified} follow-up reminder(s).");

        return self::SUCCESS;
    }
}
