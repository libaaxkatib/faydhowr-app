<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\AdminPermissionResolver;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPermission
{
    public function __construct(private AdminPermissionResolver $resolver) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user instanceof Admin) {
            return ApiResponse::error('Unauthenticated.', 'UNAUTHENTICATED', 401);
        }

        if (! $this->resolver->has($user, $permission, $request)) {
            return ApiResponse::error(
                'You do not have permission to perform this action.',
                'FORBIDDEN',
                403,
            );
        }

        return $next($request);
    }
}
