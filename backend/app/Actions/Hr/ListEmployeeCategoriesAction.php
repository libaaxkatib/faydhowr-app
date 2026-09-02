<?php

namespace App\Actions\Hr;

use App\Models\EmployeeCategory;
use Illuminate\Database\Eloquent\Collection;

class ListEmployeeCategoriesAction
{
    public function handle(): Collection
    {
        return EmployeeCategory::query()->orderBy('name')->get();
    }
}
