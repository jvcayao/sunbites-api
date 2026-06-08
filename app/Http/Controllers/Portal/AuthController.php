<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Mail\ParentWelcomeMail;
use App\Models\ParentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $parent = ParentUser::where('email', $request->email)->first();

        if (! $parent) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        if (! $parent->isActivated()) {
            return response()->json([
                'message' => 'Account not yet activated.',
                'error' => 'account_not_activated',
            ], 401);
        }

        if (! Hash::check($request->password, $parent->password ?? '')) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        $token = $parent->createToken('portal-token', ['parent'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'parent' => [
                'id' => $parent->id,
                'first_name' => $parent->first_name,
                'last_name' => $parent->last_name,
                'email' => $parent->email,
                'phone' => $parent->phone,
                'address' => $parent->address,
                'profile_photo_url' => $parent->profile_photo_url,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $parent = ParentUser::where('email', $request->email)->first();

        if ($parent) {
            $token = Password::broker('parents')->createToken($parent);
            Mail::to($parent->email)->queue(new ParentWelcomeMail($parent, $token));
        }

        return response()->json([
            'message' => 'If an account with this email exists, you will receive an email shortly.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $status = Password::broker('parents')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (ParentUser $parent, string $password) {
                $parent->forceFill([
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                ])->save();

                $parent->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Invalid or expired token.'], 422);
        }

        return response()->json(['message' => 'Password set successfully.']);
    }
}
