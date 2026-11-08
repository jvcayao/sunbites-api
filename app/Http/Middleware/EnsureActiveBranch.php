<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveBranch
{
    /**
     * Kitchen routes work on one branch's data, so they need the branch that SetActiveBranch bound
     * from the X-Branch-Id header. The POS matches this exact 422 text and sends the user to the
     * branch picker.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->bound('active_branch')) {
            return response()->json(['message' => 'No active branch selected. Please set a branch first.'], 422);
        }

        return $next($request);
    }
}
