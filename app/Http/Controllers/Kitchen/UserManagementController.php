<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\AssignUserBranchRequest;
use App\Http\Requests\Kitchen\ListStaffUsersRequest;
use App\Http\Requests\Kitchen\StoreStaffUserRequest;
use App\Http\Requests\Kitchen\UpdateStaffUserRequest;
use App\Http\Requests\Kitchen\UploadUserPhotoRequest;
use App\Http\Resources\UserResource;
use App\Mail\StaffResetPasswordMail;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;

class UserManagementController extends Controller
{
    public function index(ListStaffUsersRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $users = User::withTrashed()
            ->with(['roles', 'branches'])
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereAnyContainsLiteral(['first_name', 'last_name', 'email'], $search))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->role($role))
            ->when($filters['status'] ?? null, function ($q, $status) {
                if ($status === 'active') {
                    $q->whereNull('deleted_at')->where('is_active', true);
                } elseif ($status === 'inactive') {
                    $q->where(fn ($q) => $q->onlyTrashed()->orWhere('is_active', false));
                }
            })
            ->orderBy('last_name')
            ->paginate(20)
            ->withQueryString();

        return response()->json(UserResource::collection($users)->response()->getData(true));
    }

    public function store(StoreStaffUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $this->ensureMayAssignRole($request->user(), $validated['role']);

        $user = DB::transaction(function () use ($validated, $request) {
            $user = User::create($validated);
            $user->assignRole($validated['role']);

            if (! empty($validated['branch_ids'])) {
                $this->syncBranches($user, $validated['branch_ids'], $request->user());
            }

            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('photos', 'private');
                $user->update(['profile_photo_path' => $path]);
            }

            activity('users')
                ->causedBy($request->user())
                ->performedOn($user)
                ->withProperties(['role' => $validated['role']])
                ->log('users.created');

            return $user->load(['roles', 'branches']);
        });

        return response()->json(new UserResource($user), 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(new UserResource($user->load(['roles', 'branches'])));
    }

    public function update(UpdateStaffUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        $this->ensureMayAssignRole($request->user(), $validated['role']);

        $user = DB::transaction(function () use ($validated, $request, $user) {
            $oldRole = $user->getRoleNames()->first();

            $user->fill($validated);
            $changedFields = array_keys($user->getDirty());
            $user->save();

            if ($validated['role'] !== $oldRole) {
                $user->syncRoles($validated['role']);
                activity('users')
                    ->causedBy($request->user())
                    ->performedOn($user)
                    ->withProperties(['old_role' => $oldRole, 'new_role' => $validated['role']])
                    ->log('users.role_changed');
            }

            if (isset($validated['branch_ids'])) {
                $this->syncBranches($user, $validated['branch_ids'], $request->user());
            }

            if ($request->hasFile('profile_photo')) {
                if ($user->profile_photo_path) {
                    Storage::disk('private')->delete($user->profile_photo_path);
                }
                $path = $request->file('profile_photo')->store('photos', 'private');
                $user->update(['profile_photo_path' => $path]);
            }

            $safeFields = array_diff($changedFields, [
                'sss_number', 'pagibig_number', 'philhealth_number', 'tin_number', 'daily_rate', 'password',
            ]);

            if (! empty($safeFields)) {
                activity('users')
                    ->causedBy($request->user())
                    ->performedOn($user)
                    ->withProperties(['changed_fields' => array_values($safeFields)])
                    ->log('users.updated');
            }

            return $user->load(['roles', 'branches']);
        });

        return response()->json(new UserResource($user));
    }

    #[Authorize('deactivate', 'user')]
    public function deactivate(Request $request, User $user): JsonResponse
    {
        abort_if($user->id === $request->user()->id, 403, 'You cannot deactivate your own account.');
        abort_if($user->hasRole('admin') && User::role('admin')->count() === 1, 403, 'At least one admin must remain.');

        $user->update(['is_active' => false]);
        $user->delete();

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('users.deleted');

        return response()->json(['message' => 'Account deactivated.']);
    }

    #[Authorize('reactivate', 'user')]
    public function reactivate(Request $request, User $user): JsonResponse
    {
        $user->restore();
        $user->update(['is_active' => true]);

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('users.reactivated');

        return response()->json(new UserResource($user->load(['roles', 'branches'])));
    }

    #[Authorize('update', 'user')]
    public function sendResetEmail(Request $request, User $user): JsonResponse
    {
        $token = Password::createToken($user);
        Mail::to($user->email)->queue(new StaffResetPasswordMail($user, $token));

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('auth.password_reset');

        return response()->json(['message' => 'Password reset email sent.']);
    }

    #[Authorize('update', 'user')]
    public function uploadPhoto(UploadUserPhotoRequest $request, User $user): JsonResponse
    {
        if ($user->profile_photo_path) {
            Storage::disk('private')->delete($user->profile_photo_path);
        }

        $path = $request->file('photo')->store('photos', 'private');
        $user->update(['profile_photo_path' => $path]);

        return response()->json(['profile_photo_path' => $path]);
    }

    #[Authorize('update', 'user')]
    public function assignBranch(AssignUserBranchRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        if (! $user->branches->contains($validated['branch_id'])) {
            $user->branches()->attach($validated['branch_id'], [
                'assigned_at' => now(),
                'assigned_by' => $request->user()->id,
            ]);
        }

        return response()->json($user->load('branches')->branches);
    }

    #[Authorize('update', 'user')]
    public function detachBranch(Request $request, User $user, Branch $branch): JsonResponse
    {
        $user->branches()->detach($branch->id);

        return response()->json($user->load('branches')->branches);
    }

    private function ensureMayAssignRole(User $actor, string $role): void
    {
        if ($role === 'admin' && ! $actor->hasRole('admin')) {
            abort(403, 'Only an admin may assign the admin role.');
        }
    }

    /**
     * @param  array<int, int|string>  $branchIds
     */
    private function syncBranches(User $user, array $branchIds, User $assignedBy): void
    {
        $user->branches()->sync(array_fill_keys($branchIds, [
            'assigned_at' => now(),
            'assigned_by' => $assignedBy->id,
        ]));
    }
}
