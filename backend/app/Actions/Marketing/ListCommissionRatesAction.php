<?php

namespace App\Actions\Marketing;

use App\Models\CommissionRate;
use Illuminate\Database\Eloquent\Collection;

class ListCommissionRatesAction
{
    public function handle(): Collection
    {
        return CommissionRate::query()->with('admin')->orderByDesc('effective_from')->get();
    }
}
