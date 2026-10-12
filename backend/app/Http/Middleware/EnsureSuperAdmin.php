<?php

namespace App\Http\Middleware;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence in depth for endpoints that are reserved for Super Admin (the HR imports). It checks the role
 * itself, independently of the permission tables, so a mistaken permission grant can never open them.
 */
class EnsureSuperAdmin
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

        if ($user->role !== AdminRole::SuperAdmin) {
            return ApiResponse::error('This action is reserved for Super Admin.', 'FORBIDDEN', 403);
        }

        return $next($request);
    }
}
