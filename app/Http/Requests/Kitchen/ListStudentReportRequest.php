<?php

namespace App\Http\Requests\Kitchen;

class ListStudentReportRequest extends ExportStudentReportRequest
{
    /**
     * The export's filters plus the page size. The export request has no per_page (H8).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
