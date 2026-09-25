<?php

namespace App\Actions\Hr;

use App\Models\EmployeeDocumentCategory;
use Illuminate\Database\Eloquent\Collection;

class ListEmployeeDocumentCategoriesAction
{
    public function handle(): Collection
    {
        return EmployeeDocumentCategory::query()->orderBy('name')->get();
    }
}
