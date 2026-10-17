<?php

use App\Http\Middleware\EnsureAdminAuthentication;
use App\Http\Middleware\EnsureAdminPermission;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/',
        );

        $middleware->alias([
            'admin' => EnsureAdminAuthentication::class,
            'permission' => EnsureAdminPermission::class,
            'super_admin' => EnsureSuperAdmin::class,
        ]);

        // Sign-in, active-account and permission checks must answer BEFORE a route-bound record (employee, document,
        // company, ...) is looked up. Laravel's priority list does not know these project middleware, so they used to run
        // after SubstituteBindings: a signed-in caller without the permission got 404 for an id that does not exist and 403
        // for one that does, revealing which records exist. Prepending them keeps this order: admin -> permission ->
        // super_admin -> bindings. Ownership and scope checks inside controllers/actions are unaffected.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureAdminAuthentication::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureAdminPermission::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureSuperAdmin::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (AuthenticationException $exception, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error('Unauthenticated.', 'UNAUTHENTICATED', 401);
        });
    })->create();
