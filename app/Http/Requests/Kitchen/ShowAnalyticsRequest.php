<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\SchoolMonth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowAnalyticsRequest extends FormRequest
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
            'from_month' => ['required', 'string', Rule::enum(SchoolMonth::class)],
            'from_year' => ['required', 'integer', 'min:2020', 'max:2099'],
            'to_month' => ['required', 'string', Rule::enum(SchoolMonth::class)],
            'to_year' => ['required', 'integer', 'min:2020', 'max:2099'],
        ];
    }
}
