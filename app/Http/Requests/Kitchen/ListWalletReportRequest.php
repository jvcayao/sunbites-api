<?php

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class ListWalletReportRequest extends FormRequest
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
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'has_credit' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:name,credit'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
