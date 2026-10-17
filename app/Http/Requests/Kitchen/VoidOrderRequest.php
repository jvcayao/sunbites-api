<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class VoidOrderRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware and the policy by `#[Authorize]`.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An order that is already voided is refused before validation, so this message wins
     * over a missing `void_reason`.
     */
    protected function prepareForValidation(): void
    {
        /** @var Order $order */
        $order = $this->route('order');

        if ($order->status === OrderStatus::Voided) {
            throw new HttpResponseException(response()->json(['message' => 'Order is already voided.'], 422));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'void_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
