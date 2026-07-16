<?php

namespace App\Actions\Admin;

use App\Models\Admin;

class GetAdminAction
{
    public function handle(int $adminId): ?Admin
    {
        return Admin::query()->find($adminId);
    }
}
