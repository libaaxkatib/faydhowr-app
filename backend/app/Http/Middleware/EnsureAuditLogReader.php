<?php

namespace App\Http\Middleware;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * READ-ONLY access to the admin audit log for the Super Admin and the HR Manager (owner decision UG-4).
 *
 * The audit log used to need admins.manage. That permission (like roles.manage) is now reserved for the Super Admin, yet the HR Manager keeps reading the
 * audit trail. This is a separate, code-level rule - it checks the role itself, grants no permission, and is attached only to GET routes. It never opens
 * Admin Users or Roles & Permissions, and there is no route anywhere that creates, edits or deletes an audit record.
 */
class EnsureAuditLogReader
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

        if (! in_array($user->role, [AdminRole::SuperAdmin, AdminRole::HrManager], true)) {
            return ApiResponse::error('The audit log is reserved for the Super Admin and the HR Manager.', 'FORBIDDEN', 403);
        }

        return $next($request);
    }
}
