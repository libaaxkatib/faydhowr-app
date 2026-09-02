<?php

namespace App\Console\Commands;

use App\Enums\Marketing\FollowUpStatus;
use App\Events\Notification\NotificationRequested;
use App\Models\FollowUp;
use Illuminate\Console\Command;

/**
 * docs/HRM_MARKETING_SRS.md §14/§15: fires a reminder when a follow-up's
 * date arrives, to the assigned admin (falling back to the marketing
 * record's assigned admin). Reuses the entire existing notification
 * pipeline (NotificationChannelManager, templates, preferences, queued
 * delivery) — this command only decides WHEN to fire, never how delivery
 * happens.
 */
class NotifyDueFollowUps extends Command
{
    protected $signature = 'follow-ups:notify-due';

    protected $description = 'Dispatch reminders for marketing follow-ups due today';

    public function handle(): int
    {
        $today = now()->toDateString();

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

            event(NotificationRequested::make(
                recipient: $recipient,
                templateKey: 'follow_up_due_today',
                variables: [
                    'record_number' => $followUp->marketingRecord?->record_number,
                    'record_type' => $followUp->marketingRecord?->type?->value,
                ],
                eventId: 'follow-up-due-'.$followUp->id.'-'.$today,
            ));

            $notified++;
        }

        $this->info("Dispatched {$notified} follow-up reminder(s).");

        return self::SUCCESS;
    }
}
