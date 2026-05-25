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

class WalletReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private readonly Collection $students) {}

    public function collection(): Collection
    {
        return $this->students;
    }

    public function headings(): array
    {
        return [
            'Student Name',
            'Grade Level',
            'Current Balance',
            'Outstanding Credit',
            'Total Credited',
            'Total Debited',
            'Last Transaction Date',
        ];
    }

    /** @param  mixed  $student */
    public function map($student): array
    {
        return [
            $student->full_name,
            $student->grade_level,
            number_format((float) ($student->wallet?->balanceFloat ?? 0), 2),
            number_format((float) $student->credit_balance, 2),
            number_format((float) ($student->total_credited ?? 0), 2),
            number_format((float) ($student->total_debited ?? 0), 2),
            $student->last_transaction_date ?? '—',
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
        return 'Wallet Report';
    }
}
