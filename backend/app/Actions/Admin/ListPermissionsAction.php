<?php

namespace App\Actions\Admin;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Collection;

class ListPermissionsAction
{
    /**
     * @return Collection<int, Permission>
     */
    public function handle(): Collection
    {
        return Permission::query()
            ->orderBy('group')
            ->orderBy('key')
            ->get();
    }
}
