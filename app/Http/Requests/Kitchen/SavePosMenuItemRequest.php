<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\MenuCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared by the menu item store and update endpoints, which validate the same fields.
 */
class SavePosMenuItemRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'category' => ['required', Rule::enum(MenuCategory::class)],
            'is_subscription_item' => ['nullable', 'boolean'],
        ];
    }
}
