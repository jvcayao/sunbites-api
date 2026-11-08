<?php

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class TopUpWalletRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'payment_method' => ['required', 'in:cash,gcash,bank_transfer'],
            'reference_number' => ['nullable', 'string', 'alpha_num', 'max:50'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
