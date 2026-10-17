<?php

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionConfigRequest extends FormRequest
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
            'meal_daily_limit' => ['required', 'integer', 'min:0', 'max:10'],
            'snack_daily_limit' => ['required', 'integer', 'min:0', 'max:10'],
            'drink_daily_limit' => ['required', 'integer', 'min:0', 'max:10'],
            'extra_daily_limit' => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }
}
