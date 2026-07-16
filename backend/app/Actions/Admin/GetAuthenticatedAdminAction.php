<?php

namespace App\Actions\Admin;

use App\Models\Admin;

class GetAuthenticatedAdminAction
{
    public function handle(Admin $admin): Admin
    {
        return $admin;
    }
}
