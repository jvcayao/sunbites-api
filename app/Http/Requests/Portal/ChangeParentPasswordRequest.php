<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password as PasswordRule;

class ChangeParentPasswordRequest extends FormRequest
{
    /**
     * Parent authentication is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The current-password check and its errors-only 422 stay in the controller.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', PasswordRule::defaults(), 'confirmed'],
        ];
    }
}
