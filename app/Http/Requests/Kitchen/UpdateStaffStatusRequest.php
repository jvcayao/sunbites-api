<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\StaffStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffStatusRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
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
            'user_id' => ['required', 'integer', Rule::exists('branch_user', 'user_id')->where('branch_id', app('active_branch')->id)],
            'status' => ['required', Rule::enum(StaffStatus::class)],
        ];
    }
}
