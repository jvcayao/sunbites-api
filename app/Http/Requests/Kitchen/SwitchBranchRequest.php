<?php

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class SwitchBranchRequest extends FormRequest
{
    /**
     * Any authenticated staff member may ask; the controller checks branch membership.
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
            'from_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }
}
