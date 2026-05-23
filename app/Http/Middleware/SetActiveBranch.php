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

        if ($branchId && $branch = Branch::where('id', $branchId)->where('is_active', true)->first()) {
            app()->instance('active_branch', $branch);
        }

        return $next($request);
    }
}
