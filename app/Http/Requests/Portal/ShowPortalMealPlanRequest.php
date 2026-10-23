<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class ShowPortalMealPlanRequest extends FormRequest
{
    /**
     * Parent authentication is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The subscription and branch 403s stay in the controller, after validation.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'month' => ['nullable', 'string'],
            'week' => ['nullable', 'integer', 'min:1', 'max:4'],
        ];
    }
}
