<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\InventoryLogType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustInventoryRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The controller refuses the `sale` type after validation; checkout records sales.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(InventoryLogType::class)],
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'direction' => ['required', 'in:add,deduct'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
