<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\DayOfWeek;
use App\Enums\SchoolMonth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMealPlanRequest extends FormRequest
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
            'month' => ['required', Rule::enum(SchoolMonth::class)],
            'week' => ['required', 'integer', 'min:1', 'max:4'],
            'rows' => ['required', 'array', 'size:5'],
            'rows.*.day' => ['required', Rule::enum(DayOfWeek::class)],
            'rows.*.ulam' => ['nullable', 'string', 'max:255'],
            'rows.*.vegetables' => ['nullable', 'string', 'max:255'],
            'rows.*.fruit' => ['nullable', 'string', 'max:255'],
            'rows.*.soup' => ['nullable', 'string', 'max:255'],
            'rows.*.snacks' => ['nullable', 'string', 'max:255'],
        ];
    }
}
