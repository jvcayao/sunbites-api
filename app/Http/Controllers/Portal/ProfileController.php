<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ParentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
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
            'current_password' => ['required_with:password', 'string'],
            'password' => ['nullable', 'string', Password::min(8)->mixedCase()->numbers(), 'confirmed'],
        ]);

        $parent = $request->user();

        if (isset($validated['password'])) {
            if (! Hash::check($validated['current_password'], $parent->password ?? '')) {
                return response()->json(['errors' => ['current_password' => ['Current password is incorrect.']]], 422);
            }

            $parent->password = $validated['password'];
        }

        $parent->fill(Arr::only($validated, ['first_name', 'last_name', 'phone', 'address']));
        $parent->save();

        return response()->json($this->parentData($parent));
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
            'profile_photo_path' => $parent->profile_photo_path,
        ];
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        $parent = $request->user();

        $path = $request->file('photo')->store('photos/parents', 'private');

        $parent->update(['profile_photo_path' => $path]);

        return response()->json(['profile_photo_path' => $path]);
    }
}
