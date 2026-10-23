<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\SchoolMonth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowSubscriptionReportRequest extends FormRequest
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
            'year' => ['required', 'integer', 'min:2020', 'max:2099'],
            'status' => ['sometimes', Rule::in(['paid', 'unpaid', 'not_recorded'])],
            'grade_level' => ['sometimes', 'nullable', 'string', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
