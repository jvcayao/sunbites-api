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

class BillingReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        private readonly Collection $payments,
        private readonly array $summary,
    ) {}

    public function collection(): Collection
    {
        return $this->payments;
    }

    public function headings(): array
    {
        return [
            'Student Name',
            'Student Number',
            'Grade',
            'Section',
            'Month/Year',
            'Amount Due',
            'Status',
            'Paid On',
            'Recorded By',
        ];
    }

    /** @param  mixed  $payment */
    public function map($payment): array
    {
        return [
            $payment->student?->full_name ?? '—',
            $payment->student?->student_number ?? '—',
            $payment->student?->grade_level ?? '—',
            $payment->student?->section ?? '—',
            "{$payment->school_month?->value} {$payment->year}",
            number_format((float) $payment->amount, 2),
            $payment->status,
            $payment->recorded_at?->format('Y-m-d') ?? '—',
            $payment->recorder?->full_name ?? '—',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = $this->payments->count() + 2; // +1 for header, +1 for 1-indexed

        $summaryRow = $lastRow + 2;
        $sheet->setCellValue("A{$summaryRow}", 'Summary');
        $sheet->setCellValue('A'.($summaryRow + 1), 'Total Subscribers');
        $sheet->setCellValue('B'.($summaryRow + 1), $this->summary['total_subscribers']);
        $sheet->setCellValue('A'.($summaryRow + 2), 'Total Collected');
        $sheet->setCellValue('B'.($summaryRow + 2), $this->summary['total_collected']);
        $sheet->setCellValue('A'.($summaryRow + 3), 'Total Outstanding');
        $sheet->setCellValue('B'.($summaryRow + 3), $this->summary['total_outstanding']);
        $sheet->setCellValue('A'.($summaryRow + 4), 'Collection Rate');
        $sheet->setCellValue('B'.($summaryRow + 4), $this->summary['collection_rate'].'%');

        return [
            1 => ['font' => ['bold' => true]],
            $summaryRow => ['font' => ['bold' => true]],
        ];
    }

    public function title(): string
    {
        return 'Billing Report';
    }
}
