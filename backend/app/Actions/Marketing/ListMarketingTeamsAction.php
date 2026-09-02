<?php

namespace App\Actions\Marketing;

use App\Models\MarketingTeam;
use Illuminate\Database\Eloquent\Collection;

class ListMarketingTeamsAction
{
    public function handle(): Collection
    {
        return MarketingTeam::query()->with('members')->orderBy('name')->get();
    }
}
