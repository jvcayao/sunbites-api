<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchSelectorController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        $branches = $user->hasRole('admin')
            ? Branch::where('is_active', true)->get()
            : $user->branches()->where('branches.is_active', true)->get();

        return Inertia::render('kitchen/branch-selector', [
            'userName' => $user->first_name,
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'slug' => $branch->slug,
            ]),
        ]);
    }

    public function select(Request $request): RedirectResponse
    {
        $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id,is_active,1']]);

        $user = $request->user();
        $branchId = $request->integer('branch_id');

        if (! $user->hasRole('admin')) {
            $isAssigned = $user->branches()
                ->where('branches.id', $branchId)
                ->where('branches.is_active', true)
                ->exists();

            abort_unless($isAssigned, 403);
        }

        $fromBranchId = $request->session()->get('active_branch_id');

        $request->session()->put('active_branch_id', $branchId);

        if ($fromBranchId && $fromBranchId !== $branchId) {
            activity('branches')
                ->causedBy($user)
                ->withProperties([
                    'from_branch_id' => $fromBranchId,
                    'to_branch_id' => $branchId,
                ])
                ->log('branches.switched');
        }

        return redirect()->route('dashboard');
    }
}
