<?php

namespace App\Support\Marketing;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\FollowUp;
use App\Models\MarketingQuotation;
use App\Models\MarketingRecord;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Server-side Marketing team isolation (approved decision: Team A and Team B
 * each see their own work; the Marketing Manager and the Super Admin see both).
 *
 * Every Marketing endpoint passes through this class, so the boundary does not
 * depend on what the web panel chooses to show. A caller who is neither the
 * Super Admin nor the Marketing Manager is "restricted": a record is theirs when
 * it belongs to one of their teams, or is assigned to, created by, or brought
 * by them. A record outside that set answers 404, exactly as if it did not
 * exist, so ids cannot be probed.
 */
final class MarketingScope
{
    public static function unrestricted(Admin $admin): bool
    {
        return in_array($admin->role, [AdminRole::SuperAdmin, AdminRole::MarketingManager], true);
    }

    /**
     * @return list<int>
     */
    public static function teamIds(Admin $admin): array
    {
        return $admin->marketingTeams()->pluck('marketing_teams.id')->map(fn ($id) => (int) $id)->all();
    }

    public static function records(Builder $query, Admin $admin): Builder
    {
        if (self::unrestricted($admin)) {
            return $query;
        }

        $teams = self::teamIds($admin);

        return $query->where(function (Builder $q) use ($admin, $teams) {
            $q->where('marketing_records.assigned_admin_id', $admin->id)
                ->orWhere('marketing_records.created_by', $admin->id)
                ->orWhere('marketing_records.brought_by_admin_id', $admin->id);

            if ($teams !== []) {
                $q->orWhereIn('marketing_records.assigned_team_id', $teams);
            }
        });
    }

    public static function followUps(Builder $query, Admin $admin): Builder
    {
        if (self::unrestricted($admin)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($admin) {
            $q->where('follow_ups.assigned_admin_id', $admin->id)
                ->orWhereHas('marketingRecord', fn (Builder $record) => self::records($record, $admin));
        });
    }

    public static function canSeeRecord(MarketingRecord $record, Admin $admin): bool
    {
        if (self::unrestricted($admin)) {
            return true;
        }

        return self::records(MarketingRecord::query()->whereKey($record->getKey()), $admin)->exists();
    }

    public static function authorizeRecord(MarketingRecord $record, Admin $admin): MarketingRecord
    {
        if (! self::canSeeRecord($record, $admin)) {
            throw (new ModelNotFoundException)->setModel(MarketingRecord::class, [$record->getKey()]);
        }

        return $record;
    }

    public static function authorizeFollowUp(FollowUp $followUp, Admin $admin): FollowUp
    {
        if (self::unrestricted($admin)) {
            return $followUp;
        }

        $visible = self::followUps(FollowUp::query()->whereKey($followUp->getKey()), $admin)->exists();

        if (! $visible) {
            throw (new ModelNotFoundException)->setModel(FollowUp::class, [$followUp->getKey()]);
        }

        return $followUp;
    }

    public static function authorizeQuotation(MarketingQuotation $quotation, Admin $admin): MarketingQuotation
    {
        $record = $quotation->marketingRecord;

        if ($record === null || ! self::canSeeRecord($record, $admin)) {
            throw (new ModelNotFoundException)->setModel(MarketingQuotation::class, [$quotation->getKey()]);
        }

        return $quotation;
    }

    /**
     * A restricted caller may place work only inside their own teams: the team
     * must be one of theirs, and an assignee must belong to one of those teams
     * (or be the caller). Unrestricted callers are not limited.
     */
    public static function guardAssignment(Admin $actor, ?int $teamId, ?int $assigneeId): void
    {
        if (self::unrestricted($actor)) {
            return;
        }

        $own = self::teamIds($actor);

        if ($teamId !== null && ! in_array($teamId, $own, true)) {
            self::refuse('You can only assign work to your own marketing team.');
        }

        if ($assigneeId !== null && $assigneeId !== $actor->id) {
            $assigneeTeams = Admin::query()->whereKey($assigneeId)->first()?->marketingTeams()->pluck('marketing_teams.id')->map(fn ($id) => (int) $id)->all() ?? [];

            if (array_intersect($own, $assigneeTeams) === []) {
                self::refuse('You can only assign work to a member of your own marketing team.');
            }
        }
    }

    public static function requireUnrestricted(Admin $actor, string $message): void
    {
        if (! self::unrestricted($actor)) {
            self::refuse($message);
        }
    }

    private static function refuse(string $message): never
    {
        throw new HttpResponseException(
            ApiResponse::error($message, 'MARKETING_SCOPE_FORBIDDEN', 403),
        );
    }
}
