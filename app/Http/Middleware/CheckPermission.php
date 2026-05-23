<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Sanctum-compatible permission middleware.
 *
 * The Spatie PermissionMiddleware resolves the user via Auth::guard()->user(),
 * which uses the default 'web' guard. This fails for Sanctum Bearer-token
 * requests because the web guard has no session. This middleware resolves the
 * user from the request directly (already set by auth:sanctum) and then checks
 * the permission using the user's own guard context (web) so that existing
 * web-guard permissions are matched correctly.
 */
class CheckPermission
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            throw UnauthorizedException::notLoggedIn();
        }

        $permissions = explode('|', $permission);

        if (! $user->hasAnyPermission($permissions)) {
            throw UnauthorizedException::forPermissions($permissions);
        }

        return $next($request);
    }
}
