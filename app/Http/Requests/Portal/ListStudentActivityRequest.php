<?php

namespace App\Http\Requests\Portal;

use Illuminate\Foundation\Http\FormRequest;

class ListStudentActivityRequest extends FormRequest
{
    /**
     * The controller's #[Authorize('view', 'student')] (the `can` middleware) runs before this request is validated.
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
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'payment_method' => ['nullable', 'string', 'in:cash,wallet'],
        ];
    }
}
