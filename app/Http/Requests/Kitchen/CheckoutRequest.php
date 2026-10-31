<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware; the discount check runs in CheckoutAction.
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
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.pos_menu_item_id' => ['required', 'integer', 'exists:pos_menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'alpha_num', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'discount_type' => ['nullable', 'in:percent,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'use_credit' => ['boolean'],
        ];
    }
}
