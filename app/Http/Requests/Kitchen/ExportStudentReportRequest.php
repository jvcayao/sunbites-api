<?php

namespace App\Http\Requests\Kitchen;

use App\Enums\SchoolMonth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportStudentReportRequest extends FormRequest
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
            'status' => ['nullable', 'string'],
            'grade' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:100'],
            'payment_status' => ['nullable', 'string', 'in:paid,unpaid,voided'],
            'payment_from' => ['nullable', 'string', Rule::enum(SchoolMonth::class)],
            'payment_to' => ['nullable', 'string', Rule::enum(SchoolMonth::class)],
        ];
    }
}
