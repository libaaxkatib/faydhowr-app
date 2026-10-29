<?php

namespace App\Support\Marketing;

use App\Models\FollowUp;
use App\Models\MarketingRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The one text search of the Marketing screens (records and follow-ups). Case does not matter, wildcard characters typed by the user are taken
 * literally, and it works on every database (LOWER ... LIKE, not PostgreSQL's ILIKE). It only narrows what the caller's team scope already allows.
 *
 * A record matches when its number, or any of these fields of its XARUN or PROJECT details, contains the text: facility, manager, company, responsible
 * person, phone, location, project type.
 */
final class MarketingSearch
{
    /** Escaped LIKE pattern for a typed search ("%" and "_" are literal), or null when nothing was typed. */
    public static function pattern(?string $term): ?string
    {
        $needle = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $term)));

        return $needle === '' ? null : '%'.addcslashes($needle, '%_\\').'%';
    }

    /**
     * @param  Builder<MarketingRecord>  $records
     */
    public static function applyToRecords(Builder $records, ?string $term): void
    {
        $like = self::pattern($term);

        if ($like === null) {
            return;
        }

        $records->where(function (Builder $match) use ($like): void {
            $match->whereRaw("LOWER(record_number) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereHas('xarunDetail', fn (Builder $detail) => self::anyOf($detail, ['facility_name', 'manager_name', 'phone', 'location'], $like))
                ->orWhereHas('projectDetail', fn (Builder $detail) => self::anyOf($detail, ['company_name', 'responsible_person_name', 'phone', 'location', 'project_type'], $like));
        });
    }

    /**
     * @param  Builder<FollowUp>  $followUps
     */
    public static function applyToFollowUps(Builder $followUps, ?string $term): void
    {
        $like = self::pattern($term);

        if ($like === null) {
            return;
        }

        $followUps->where(function (Builder $match) use ($term, $like): void {
            $match->whereHas('marketingRecord', fn (Builder $record) => self::applyToRecords($record, $term))
                ->orWhereHas('assignedAdmin', fn (Builder $admin) => $admin->whereRaw("LOWER(full_name) LIKE ? ESCAPE '\\'", [$like]));
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @param  list<string>  $columns
     */
    private static function anyOf(Builder $query, array $columns, string $like): void
    {
        $query->where(function (Builder $any) use ($columns, $like): void {
            foreach ($columns as $column) {
                $any->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '\\'", [$like]);
            }
        });
    }
}
