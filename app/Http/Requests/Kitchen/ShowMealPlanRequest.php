<?php

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class ShowMealPlanRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `month` and `week` were never validated, so each rule accepts any value (hazard H8).
     * The controller still reads them with `input()` and the same defaults.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'month' => ['nullable'],
            'week' => ['nullable'],
        ];
    }
}
