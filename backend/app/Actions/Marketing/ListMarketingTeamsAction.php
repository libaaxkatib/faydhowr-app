<?php

namespace App\Actions\Marketing;

use App\Models\Admin;
use App\Models\MarketingTeam;
use App\Support\Marketing\MarketingScope;
use Illuminate\Database\Eloquent\Collection;

class ListMarketingTeamsAction
{
    public function handle(Admin $actor): Collection
    {
        $query = MarketingTeam::query()->with('members');

        if (! MarketingScope::unrestricted($actor)) {
            $query->whereIn('id', MarketingScope::teamIds($actor));
        }

        return $query->orderBy('name')->get();
    }
}
