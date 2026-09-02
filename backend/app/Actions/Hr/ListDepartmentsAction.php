<?php

namespace App\Actions\Hr;

use App\Models\Department;
use Illuminate\Database\Eloquent\Collection;

class ListDepartmentsAction
{
    public function handle(): Collection
    {
        return Department::query()->orderBy('name')->get();
    }
}
