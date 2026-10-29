<?php

namespace App\Actions\Hr;

use App\Enums\ClientCategory;
use App\Models\ClientCompany;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Companies & Workplaces list. Without paging parameters it returns every company (other screens use it for dropdowns). With `page` or
 * `per_page` it returns one page of the companies that match every filter at once: search AND category AND status AND workplaces.
 *
 * Search looks in the company's name (also with the spaces left out, so "5GHOTEL" finds "5G HOTEL"), its other names (aliases), contact person,
 * phone and location, and in the names and locations of its workplaces. Case does not matter.
 */
class ListClientCompaniesAction
{
    public const DEFAULT_PER_PAGE = 20;

    /**
     * @param  array{search?: ?string, category?: ?string, status?: ?string, workplaces?: ?string, page?: int, per_page?: int}  $filters
     */
    public function handle(array $filters = []): Collection|LengthAwarePaginator
    {
        $query = $this->filtered($filters, withCategory: true)
            ->with(['workLocations', 'aliases'])
            ->withCount('agreements')
            ->orderBy('name')
            ->orderBy('id');

        if (! isset($filters['page']) && ! isset($filters['per_page'])) {
            return $query->get();
        }

        return $query->paginate(perPage: (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), page: (int) ($filters['page'] ?? 1));
    }

    /**
     * What the screen shows beside the results: how many companies match everything except the category (the numbers on the Company / Guri
     * buttons), and how many companies exist in all.
     *
     * @param  array<string, mixed>  $filters
     * @return array{counts: array{all: int, company: int, guri: int}, unfiltered_total: int}
     */
    public function summary(array $filters): array
    {
        $byCategory = $this->filtered($filters, withCategory: false)->reorder()->selectRaw('category, count(*) as aggregate')->groupBy('category')->pluck('aggregate', 'category');
        $company = (int) ($byCategory[ClientCategory::Company->value] ?? 0);
        $guri = (int) ($byCategory[ClientCategory::Guri->value] ?? 0);

        return [
            'counts' => ['all' => $company + $guri + (int) $byCategory->except([ClientCategory::Company->value, ClientCategory::Guri->value])->sum(), 'company' => $company, 'guri' => $guri],
            'unfiltered_total' => ClientCompany::query()->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ClientCompany>
     */
    private function filtered(array $filters, bool $withCategory): Builder
    {
        $query = ClientCompany::query();

        if ($withCategory && ! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (($filters['workplaces'] ?? null) === 'with') {
            $query->has('workLocations');
        } elseif (($filters['workplaces'] ?? null) === 'without') {
            $query->doesntHave('workLocations');
        }

        $this->applySearch($query, (string) ($filters['search'] ?? ''));

        return $query;
    }

    /**
     * @param  Builder<ClientCompany>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $needle = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $term)));

        if ($needle === '') {
            return;
        }

        $like = '%'.addcslashes($needle, '%_\\').'%';
        $compact = '%'.addcslashes(str_replace(' ', '', $needle), '%_\\').'%';

        $query->where(function (Builder $match) use ($like, $compact): void {
            foreach (['name', 'contact_person', 'phone', 'location'] as $column) {
                $match->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '\\'", [$like]);
            }

            $match->orWhereRaw("REPLACE(LOWER(name), ' ', '') LIKE ? ESCAPE '\\'", [$compact]);
            $match->orWhereHas('aliases', fn (Builder $alias) => $alias
                ->whereRaw("LOWER(alias) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("REPLACE(LOWER(alias), ' ', '') LIKE ? ESCAPE '\\'", [$compact]));
            $match->orWhereHas('workLocations', fn (Builder $place) => $place
                ->whereRaw("LOWER(name) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("LOWER(location) LIKE ? ESCAPE '\\'", [$like]));
        });
    }
}
