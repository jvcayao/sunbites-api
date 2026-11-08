<?php

namespace App\Http\Requests\Kitchen;

use App\Http\Requests\Kitchen\Concerns\StaffUserRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password as PasswordRule;

class StoreStaffUserRequest extends FormRequest
{
    use StaffUserRules;

    /**
     * Role authorization is enforced by route middleware; the admin-role check runs in the
     * controller, after validation.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->staffRules(),
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', PasswordRule::min(8)->mixedCase()->numbers(), 'confirmed'],
        ];
    }
}
