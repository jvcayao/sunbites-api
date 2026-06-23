<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Mail\StaffResetPasswordMail;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password as PasswordRule;

class UserManagementController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $users = User::withTrashed()
            ->with(['roles', 'branches'])
            ->when($request->search, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->when($request->role, fn ($q, $role) => $q->role($role))
            ->when($request->status, function ($q, $status) {
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            ...$this->staffRules(),
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', PasswordRule::min(8)->mixedCase()->numbers(), 'confirmed'],
        ]);

        if ($validated['role'] === 'admin' && ! $request->user()->hasRole('admin')) {
            abort(403, 'Only an admin may assign the admin role.');
        }

        $user = DB::transaction(function () use ($validated, $request) {
            $user = User::create($validated);
            $user->assignRole($validated['role']);

            if (! empty($validated['branch_ids'])) {
                $user->branches()->sync(array_fill_keys($validated['branch_ids'], [
                    'assigned_at' => now(),
                    'assigned_by' => $request->user()->id,
                ]));
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

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            ...$this->staffRules(),
            'email' => ['required', 'email', "unique:users,email,{$user->id}"],
        ]);

        if ($validated['role'] === 'admin' && ! $request->user()->hasRole('admin')) {
            abort(403, 'Only an admin may assign the admin role.');
        }

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
                $user->branches()->sync(array_fill_keys($validated['branch_ids'], [
                    'assigned_at' => now(),
                    'assigned_by' => $request->user()->id,
                ]));
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

    public function deactivate(Request $request, User $user): JsonResponse
    {
        $this->authorize('deactivate', $user);
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

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $this->authorize('reactivate', $user);
        $user->restore();
        $user->update(['is_active' => true]);

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('users.reactivated');

        return response()->json(new UserResource($user->load(['roles', 'branches'])));
    }

    public function sendResetEmail(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $token = Password::createToken($user);
        Mail::to($user->email)->queue(new StaffResetPasswordMail($user, $token));

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('auth.password_reset');

        return response()->json(['message' => 'Password reset email sent.']);
    }

    public function uploadPhoto(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        if ($user->profile_photo_path) {
            Storage::disk('private')->delete($user->profile_photo_path);
        }

        $path = $request->file('photo')->store('photos', 'private');
        $user->update(['profile_photo_path' => $path]);

        return response()->json(['profile_photo_path' => $path]);
    }

    public function assignBranch(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        if (! $user->branches->contains($validated['branch_id'])) {
            $user->branches()->attach($validated['branch_id'], [
                'assigned_at' => now(),
                'assigned_by' => $request->user()->id,
            ]);
        }

        return response()->json($user->load('branches')->branches);
    }

    public function detachBranch(Request $request, User $user, Branch $branch): JsonResponse
    {
        $this->authorize('update', $user);
        $user->branches()->detach($branch->id);

        return response()->json($user->load('branches')->branches);
    }

    /** @return array<string, array<int, mixed>> */
    private function staffRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:100'],
            'birthday' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:male,female,other'],
            'civil_status' => ['nullable', 'in:single,married,widowed,separated'],
            'phone' => ['nullable', 'string', 'max:30'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:100'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'zip_code' => ['nullable', 'string', 'max:10'],
            'position' => ['nullable', 'string', 'max:100'],
            'employment_type' => ['nullable', 'in:full_time,part_time,contractual'],
            'date_hired' => ['nullable', 'date'],
            'daily_rate' => ['nullable', 'numeric', 'min:0'],
            'sss_number' => ['nullable', 'string', 'max:50'],
            'pagibig_number' => ['nullable', 'string', 'max:50'],
            'philhealth_number' => ['nullable', 'string', 'max:50'],
            'tin_number' => ['nullable', 'string', 'max:50'],
            'role' => ['required', 'exists:roles,name'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'exists:branches,id'],
            'profile_photo' => ['nullable', 'file', 'mimes:jpeg,png,webp', 'max:2048'],
        ];
    }
}
