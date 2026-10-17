<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\SchoolMonth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPaymentRequest extends FormRequest
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
            'school_month' => ['required', Rule::enum(SchoolMonth::class)],
            'year' => ['required', 'integer', 'digits:4'],
            'amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
