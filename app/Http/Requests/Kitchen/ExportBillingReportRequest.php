<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\SchoolMonth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportBillingReportRequest extends FormRequest
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
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'school_month' => ['nullable', 'string', Rule::enum(SchoolMonth::class)],
            'status' => ['nullable', 'string', 'in:paid,unpaid,voided'],
            'grade_level' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:100'],
            'recorded_by' => ['nullable', 'integer', 'exists:users,id'],
            'recorded_from' => ['nullable', 'date'],
            'recorded_to' => ['nullable', 'date'],
        ];
    }
}
