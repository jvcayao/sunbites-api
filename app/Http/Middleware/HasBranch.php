<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HasBranch
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->hasRole('admin')) {
            if (! $request->session()->has('active_branch_id')) {
                return redirect()->route('branch-selector');
            }

            return $next($request);
        }

        $assignedBranches = $user->branches()->where('branches.is_active', true)->get();

        if ($assignedBranches->isEmpty()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => __('Your account has no branch assigned. Contact your administrator.'),
            ]);
        }

        if (! $request->session()->has('active_branch_id')) {
            if ($assignedBranches->count() === 1) {
                $request->session()->put('active_branch_id', $assignedBranches->first()->id);

                return $next($request);
            }

            return redirect()->route('branch-selector');
        }

        return $next($request);
    }
}
