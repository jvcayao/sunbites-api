<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BranchManagementController extends Controller
{
    public function index(): Response
    {
        $branches = Branch::withTrashed()
            ->withCount(['users as staff_count' => fn ($q) => $q->whereNull('users.deleted_at')])
            ->orderBy('name')
            ->get();

        return Inertia::render('kitchen/references/branches/index', [
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'slug' => $branch->slug,
                'gcash_number' => $branch->gcash_number,
                'address' => $branch->address,
                'is_active' => $branch->is_active,
                'staff_count' => $branch->staff_count,
                'deleted_at' => $branch->deleted_at,
            ]),
        ]);
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'gcash_number' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $branch->update($validated);

        activity('branches')
            ->causedBy($request->user())
            ->performedOn($branch)
            ->log('branches.updated');

        return back()->with('success', 'Branch updated successfully.');
    }

    public function toggleActive(Request $request, Branch $branch): RedirectResponse
    {
        $activeCount = Branch::where('is_active', true)->count();

        abort_if($branch->is_active && $activeCount === 1, 403, 'At least one branch must remain active.');

        $branch->update(['is_active' => ! $branch->is_active]);

        activity('branches')
            ->causedBy($request->user())
            ->performedOn($branch)
            ->withProperties(['is_active' => $branch->is_active])
            ->log('branches.toggled');

        return back()->with('success', $branch->is_active ? 'Branch activated.' : 'Branch deactivated.');
    }
}
