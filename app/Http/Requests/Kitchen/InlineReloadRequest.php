<?php

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class InlineReloadRequest extends FormRequest
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
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'payment_method' => ['required', 'in:cash,gcash'],
            'reference_number' => ['nullable', 'string', 'alpha_num', 'max:50'],
            'order_context' => ['nullable', 'string', 'max:255'],
        ];
    }
}
