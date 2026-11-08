<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class ResetParentPasswordRequest extends FormRequest
{
    /**
     * The reset route is public; the password broker checks the token.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The controller passes `$request->only(...)` to the broker, not `validated()`, which
     * would drop `password_confirmation` (hazard H7). Parents keep `min:8` (H13).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
