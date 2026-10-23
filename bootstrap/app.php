<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureActiveBranch;
use App\Http\Middleware\SetActiveBranch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => null);

        // Must run BEFORE SubstituteBindings. Route model binding resolves {student},
        // {order} and friends through the HasBranch global scope, and that scope no-ops
        // unless `active_branch` is already bound — so binding first would resolve records
        // from any branch. Prepending is what makes branch isolation real on bound routes.
        $middleware->api(prepend: [
            SetActiveBranch::class,
        ]);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'branch.required' => EnsureActiveBranch::class,
            'permission' => CheckPermission::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->renderable(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                if ($e instanceof AuthenticationException) {
                    return response()->json(['message' => 'Unauthenticated.'], 401);
                }

                if ($e instanceof AccessDeniedHttpException && $e->getPrevious() instanceof AuthorizationException) {
                    return response()->json(['message' => 'Forbidden.'], 403);
                }

                if ($e instanceof NotFoundHttpException && $e->getPrevious() instanceof ModelNotFoundException) {
                    return response()->json(['message' => 'Not found.'], 404);
                }

                if ($e instanceof ValidationException) {
                    return response()->json(['message' => 'Validation failed.', 'errors' => $e->errors()], 422);
                }
            }
        });
    })->create();
