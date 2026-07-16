<?php

namespace App\Http\Middleware;

use App\Enums\AdminStatus;
use App\Models\Admin;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAuthentication
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof Admin) {
            return ApiResponse::error('Unauthenticated.', 'UNAUTHENTICATED', 401);
        }

        $user->refresh();

        if ($user->status !== AdminStatus::Active) {
            return ApiResponse::error(
                'Admin account is inactive.',
                'ADMIN_ACCOUNT_INACTIVE',
                403,
            );
        }

        return $next($request);
    }
}
