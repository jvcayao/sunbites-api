<?php

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class ExportSalesReportRequest extends FormRequest
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
            'payment_method' => ['nullable', 'string'],
            'is_walk_in' => ['nullable', 'boolean'],
            'cashier_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
