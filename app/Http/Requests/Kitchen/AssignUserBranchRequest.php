<?php

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class AssignUserBranchRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware and the policy by `#[Authorize]`,
     * which runs before this request validates.
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
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ];
    }
}
