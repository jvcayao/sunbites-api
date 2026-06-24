<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ParentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->parentData($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $parent = $request->user();
        $parent->update($validated);

        return response()->json($this->parentData($parent));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        $parent = $request->user();

        if (! Hash::check($validated['current_password'], $parent->password ?? '')) {
            return response()->json(
                ['errors' => ['current_password' => ['Current password is incorrect.']]],
                422
            );
        }

        $parent->update(['password' => $validated['password']]);

        // Revoke all tokens so the user must re-authenticate on all devices.
        $parent->tokens()->delete();

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        $parent = $request->user();
        $oldPath = $parent->profile_photo_path;

        $path = $request->file('photo')->store('photos/parents', 'public');

        $parent->update(['profile_photo_path' => $path]);

        // Delete only after the DB update succeeds — preserves the old photo
        // if the update throws, rather than leaving the parent with a broken avatar.
        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json(['profile_photo_url' => Storage::url($path)]);
    }

    /** @return array<string, mixed> */
    private function parentData(ParentUser $parent): array
    {
        return [
            'id' => $parent->id,
            'first_name' => $parent->first_name,
            'last_name' => $parent->last_name,
            'email' => $parent->email,
            'phone' => $parent->phone,
            'address' => $parent->address,
            'profile_photo_url' => $parent->profile_photo_url,
            'has_subscription_student' => $parent->hasSubscriptionStudent(),
        ];
    }
}
