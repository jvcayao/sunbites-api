<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        $branches = Branch::withTrashed()
            ->withCount(['users as staff_count' => fn ($q) => $q->whereNull('users.deleted_at')])
            ->selectRaw('branches.*')
            ->selectSub(
                DB::table('students')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('students.branch_id', 'branches.id')
                    ->whereNull('students.deleted_at'),
                'student_count'
            )
            ->orderBy('name')
            ->get();

        return response()->json($branches->map(fn (Branch $branch) => [
            'id' => $branch->id,
            'name' => $branch->name,
            'slug' => $branch->slug,
            'address' => $branch->address,
            'gcash_number' => $branch->gcash_number,
            'is_active' => $branch->is_active,
            'staff_count' => $branch->staff_count,
            'student_count' => (int) $branch->student_count,
            'orders_today' => 0, // placeholder until POS order tracking is implemented
            'deleted_at' => $branch->deleted_at,
        ]));
    }

    public function update(Request $request, Branch $branch): JsonResponse
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

        return response()->json([
            'id' => $branch->id,
            'name' => $branch->name,
            'slug' => $branch->slug,
            'address' => $branch->address,
            'gcash_number' => $branch->gcash_number,
            'is_active' => $branch->is_active,
        ]);
    }

    public function toggleActive(Request $request, Branch $branch): JsonResponse
    {
        $activeCount = Branch::where('is_active', true)->count();

        if ($branch->is_active && $activeCount === 1) {
            return response()->json(['message' => 'At least one branch must remain active.'], 422);
        }

        $branch->update(['is_active' => ! $branch->is_active]);

        activity('branches')
            ->causedBy($request->user())
            ->performedOn($branch)
            ->withProperties(['is_active' => $branch->is_active])
            ->log('branches.toggled');

        return response()->json(['is_active' => $branch->is_active]);
    }
}
