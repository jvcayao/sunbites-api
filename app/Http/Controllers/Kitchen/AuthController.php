<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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
        $user = $request->user()->load('branches');

        return response()->json([
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'branches' => $user->branches()->select('id', 'name', 'slug')->get(),
        ]);
    }
}
