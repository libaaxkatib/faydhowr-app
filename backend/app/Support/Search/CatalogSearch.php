<?php

namespace App\Support\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * Driver-aware catalog search (API Design §15): PostgreSQL matches through
 * ILIKE so the pg_trgm GIN indexes are used; SQLite (automated tests) falls
 * back to LIKE, which is case-insensitive for ASCII. Ranking follows §15.5 —
 * exact name, name prefix, whole-word within name, then description-only
 * matches — with sort_order → featured → alphabetical tie-breakers, all
 * computed server-side.
 */
final class CatalogSearch
{
    /**
     * Constrain the query to rows matching the term in name or description.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function filter(Builder $query, string $term, string $nameColumn, string $descriptionColumn): Builder
    {
        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $pattern = '%'.self::escapeLike($term).'%';

        return $query->where(function (Builder $match) use ($operator, $pattern, $nameColumn, $descriptionColumn): void {
            $match->where($nameColumn, $operator, $pattern)
                ->orWhere($descriptionColumn, $operator, $pattern);
        });
    }

    /**
     * Order the query by match tier (§15.5), then by the documented
     * tie-breakers. Pass a null sort-order column for tables without one.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function rank(
        Builder $query,
        string $term,
        string $nameColumn,
        ?string $sortOrderColumn,
        string $featuredColumn,
    ): Builder {
        $lower = mb_strtolower($term);
        $escaped = self::escapeLike($lower);

        $query->orderByRaw(
            sprintf(
                'CASE'
                .' WHEN LOWER(%1$s) = ? THEN 1'
                .' WHEN LOWER(%1$s) LIKE ? THEN 2'
                .' WHEN LOWER(%1$s) LIKE ? THEN 3'
                .' WHEN LOWER(%1$s) LIKE ? THEN 4'
                .' ELSE 5 END',
                $nameColumn,
            ),
            [$lower, $escaped.'%', '% '.$escaped.'%', '%'.$escaped.'%'],
        );

        if ($sortOrderColumn !== null) {
            $query->orderBy($sortOrderColumn);
        }

        return $query
            ->orderByDesc($featuredColumn)
            ->orderBy($nameColumn)
            ->orderBy('id');
    }

    /**
     * Escape LIKE/ILIKE wildcards so user input is matched literally rather
     * than as a pattern (defends against wildcard-stuffing / filter bypass).
     */
    public static function escapeLike(string $term): string
    {
        return addcslashes($term, '%_\\');
    }
}
