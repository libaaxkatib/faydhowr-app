<?php

namespace App\Actions\Hr;

use App\Models\ClientCompany;
use Illuminate\Database\Eloquent\Collection;

class ListClientCompaniesAction
{
    public function handle(): Collection
    {
        return ClientCompany::query()->with(['workLocations', 'aliases'])->withCount('agreements')->orderBy('name')->get();
    }
}
