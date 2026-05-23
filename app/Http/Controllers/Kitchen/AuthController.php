<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password) || ! $user->is_active) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        $token = $user->createToken('staff-token', ['staff'])->plainTextToken;

        activity('auth')
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip(), 'branch_id' => $request->header('X-Branch-Id')])
            ->log('auth.login');

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'branches' => $user->branches()->select('id', 'name', 'slug')->get(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        activity('auth')
            ->causedBy($request->user())
            ->withProperties(['ip' => $request->ip()])
            ->log('auth.logout');

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'branches' => $user->branches()->select('id', 'name', 'slug')->get(),
        ]);
    }

    public function sendResetEmail(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        activity('auth')
            ->withProperties(['ip' => $request->ip()])
            ->log('auth.password_reset_requested');

        return response()->json(['message' => 'Password reset link sent if the email exists.']);
    }

    public function setBranch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'from_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ]);

        $user = $request->user();
        $branch = Branch::find($validated['branch_id']);

        if (! $user->can('access.any_branch') && ! $user->branches->contains($branch)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        activity('auth')
            ->causedBy($user)
            ->withProperties([
                'from_branch_id' => $validated['from_branch_id'] ?? null,
                'to_branch_id' => $branch->id,
                'ip' => $request->ip(),
            ])
            ->log('branches.switched');

        return response()->json(['id' => $branch->id, 'name' => $branch->name, 'slug' => $branch->slug]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', \Illuminate\Validation\Rules\Password::defaults(), 'confirmed'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->update(['password' => $password]);
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Unable to reset password. Please request a new link.'], 422);
        }

        return response()->json(['message' => __($status)]);
    }
}
