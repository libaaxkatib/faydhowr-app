<?php

namespace App\Actions\Hr;

use App\Models\Position;
use Illuminate\Database\Eloquent\Collection;

class ListPositionsAction
{
    public function handle(): Collection
    {
        return Position::query()->with('department')->orderBy('name')->get();
    }
}
