<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\SchoolMonth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMealPlanWeekVisibilityRequest extends FormRequest
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
            'month' => ['required', Rule::enum(SchoolMonth::class)],
            'week' => ['required', 'integer', 'min:1', 'max:4'],
            'visible_to_parents' => ['required', 'boolean'],
        ];
    }
}
