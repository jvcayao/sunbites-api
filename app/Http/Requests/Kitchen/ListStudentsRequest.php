<?php

namespace App\Http\Requests\Kitchen;

use Illuminate\Foundation\Http\FormRequest;

class ListStudentsRequest extends FormRequest
{
    /**
     * Role authorization is enforced by route middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * These filters were never validated, so each rule accepts any value (hazard H8). The
     * controller keeps the same truthiness checks on what comes back.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'deleted' => ['nullable'],
            'search' => ['nullable'],
            'grade' => ['nullable'],
            'status' => ['nullable'],
            'type' => ['nullable'],
            'month' => ['nullable'],
            'payment_status' => ['nullable'],
            'year' => ['nullable'],
        ];
    }
}
