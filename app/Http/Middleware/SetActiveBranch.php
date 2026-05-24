<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetActiveBranch
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $branchId = $request->header('X-Branch-Id');

        $user = $request->user('sanctum');

        if ($branchId && $user) {
            $branch = Branch::where('id', $branchId)->where('is_active', true)->first();

            if ($branch) {
                if ($user->can('access.any_branch') || $user->branches->contains($branch)) {
                    app()->instance('active_branch', $branch);
                } else {
                    return response()->json(['message' => 'Forbidden.'], 403);
                }
            }
        }

        return $next($request);
    }
}
