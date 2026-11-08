<?php

namespace App\Http\Requests\Kitchen;

use App\Http\Requests\Kitchen\Concerns\StaffUserRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffUserRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->route('user');

        return [
            ...$this->staffRules(),
            'email' => ['required', 'email', "unique:users,email,{$user->id}"],
        ];
    }
}
