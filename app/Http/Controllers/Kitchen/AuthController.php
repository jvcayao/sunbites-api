<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ResetStaffPasswordRequest;
use App\Http\Requests\Kitchen\SendStaffResetLinkRequest;
use App\Http\Requests\Kitchen\StaffLoginRequest;
use App\Http\Requests\Kitchen\SwitchBranchRequest;
use App\Mail\StaffResetPasswordMail;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function login(StaffLoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password) || ! $user->is_active) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        $token = $user->createToken('staff-token', ['staff'])->plainTextToken;

        activity('auth')
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip(), 'branch_id' => $request->header('X-Branch-Id')])
            ->log('auth.login');

        return response()->json([
            'token' => $token,
            'user' => $this->userData($user),
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
        return response()->json($this->userData($request->user()));
    }

    public function sendResetEmail(SendStaffResetLinkRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if ($user && $user->is_active) {
            $token = Password::createToken($user);
            Mail::to($user->email)->queue(new StaffResetPasswordMail($user, $token));
        }

        activity('auth')
            ->withProperties(['ip' => $request->ip()])
            ->log('auth.password_reset_requested');

        return response()->json(['message' => 'If an account with this email exists, you will receive an email shortly.']);
    }

    public function setBranch(SwitchBranchRequest $request): JsonResponse
    {
        $validated = $request->validated();

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

    public function resetPassword(ResetStaffPasswordRequest $request): JsonResponse
    {
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

    /** @return array<string, mixed> */
    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'branches' => $user->branches()->select('id', 'name', 'slug')->get(),
        ];
    }
}
