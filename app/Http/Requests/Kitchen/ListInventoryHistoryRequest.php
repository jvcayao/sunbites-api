<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\InventoryLogType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInventoryHistoryRequest extends FormRequest
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
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'type' => ['nullable', Rule::enum(InventoryLogType::class)],
            'item_id' => ['nullable', 'integer', 'exists:inventory_items,id'],
        ];
    }
}
