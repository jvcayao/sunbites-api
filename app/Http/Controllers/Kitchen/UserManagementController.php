<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::withTrashed()
            ->with(['roles', 'branches'])
            ->when($request->search, fn ($q, $search) => $q->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
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

        return Inertia::render('kitchen/references/users/index', [
            'users' => UserResource::collection($users),
            'filters' => $request->only(['search', 'role', 'status']),
            'roles' => Role::pluck('name'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('kitchen/references/users/create', [
            'roles' => Role::pluck('name'),
            'branches' => Branch::where('is_active', true)->get(['id', 'name', 'slug']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            ...$this->staffRules(),
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', PasswordRule::min(8)->mixedCase()->numbers(), 'confirmed'],
        ]);

        DB::transaction(function () use ($validated, $request) {
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
        });

        return redirect()->route('kitchen.references.users.index')
            ->with('success', 'Staff account created successfully.');
    }

    public function show(User $user): Response
    {
        $user->load(['roles', 'branches']);

        return Inertia::render('kitchen/references/users/show', [
            'user' => new UserResource($user),
        ]);
    }

    public function edit(User $user): Response
    {
        $user->load(['roles', 'branches']);

        return Inertia::render('kitchen/references/users/edit', [
            'user' => new UserResource($user),
            'roles' => Role::pluck('name'),
            'branches' => Branch::where('is_active', true)->get(['id', 'name', 'slug']),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            ...$this->staffRules(),
            'email' => ['required', 'email', "unique:users,email,{$user->id}"],
        ]);

        DB::transaction(function () use ($validated, $request, $user) {
            $oldRole = $user->getRoleNames()->first();

            $user->fill($validated);
            $changedFields = $user->getDirty();
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

            if (! empty($changedFields)) {
                $safeFields = array_diff(array_keys($changedFields), [
                    'sss_number', 'pagibig_number', 'philhealth_number', 'tin_number', 'daily_rate', 'password',
                ]);

                activity('users')
                    ->causedBy($request->user())
                    ->performedOn($user)
                    ->withProperties(['changed_fields' => array_values($safeFields)])
                    ->log('users.updated');
            }
        });

        return redirect()->route('kitchen.references.users.show', $user)
            ->with('success', 'Staff profile updated.');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        abort_if($user->id === $request->user()->id, 403, 'You cannot deactivate your own account.');
        abort_if($user->hasRole('admin') && User::role('admin')->count() === 1, 403, 'At least one admin account must remain active.');

        $user->update(['is_active' => false]);
        $user->delete();

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('users.deleted');

        return redirect()->route('kitchen.references.users.index')
            ->with('success', 'Account deactivated.');
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        $user->restore();
        $user->update(['is_active' => true]);

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('users.reactivated');

        return redirect()->route('kitchen.references.users.show', $user)
            ->with('success', 'Account reactivated.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        Password::sendResetLink(['email' => $user->email]);

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('auth.password_reset');

        return back()->with('success', 'Password reset email sent.');
    }

    public function setPassword(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', PasswordRule::min(8)->mixedCase()->numbers(), 'confirmed'],
        ]);

        $user->update(['password' => $validated['password']]);

        activity('users')
            ->causedBy($request->user())
            ->performedOn($user)
            ->log('users.password_set');

        return back()->with('success', 'Password updated successfully.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
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
