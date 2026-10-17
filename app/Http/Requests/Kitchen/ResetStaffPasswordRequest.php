<?php

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password as PasswordRule;

class ResetStaffPasswordRequest extends FormRequest
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
     * would drop `password_confirmation` (hazard H7).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', PasswordRule::defaults(), 'confirmed'],
        ];
    }
}
