<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Explicit field allowlist — never exposes SSS, PhilHealth, PAGIBIG, TIN,
 * passwords, or any internal sensitive identifiers.
 */
class StudentsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private readonly Collection $students) {}

    public function collection(): Collection
    {
        return $this->students;
    }

    public function headings(): array
    {
        return [
            'Student Number',
            'First Name',
            'Last Name',
            'Grade Level',
            'Section',
            'Status',
            'Enrollment Date',
            'Type',
            'Wallet Balance',
            'Total Spent',
            'Primary Contact',
            'Contact Phone',
        ];
    }

    /** @param  mixed  $student */
    public function map($student): array
    {
        $primaryContact = $student->contacts->firstWhere('is_primary', true);

        return [
            $student->student_number,
            $student->first_name,
            $student->last_name,
            $student->grade_level,
            $student->section ?? '—',
            $student->enrollment_status?->value ?? '—',
            $student->enrollment_date?->format('Y-m-d') ?? '—',
            $student->student_type?->value ?? '—',
            number_format((float) ($student->wallet?->balanceFloat ?? 0), 2),
            number_format((float) $student->total_spent, 2),
            $primaryContact?->full_name ?? '—',
            $primaryContact?->phone ?? '—',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    public function title(): string
    {
        return 'Students';
    }
}
