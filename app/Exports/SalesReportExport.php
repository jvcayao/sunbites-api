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

class SalesReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(
        private readonly Collection $orders,
        private readonly string $branchName,
        private readonly string $dateFrom,
        private readonly string $dateTo,
    ) {}

    public function collection(): Collection
    {
        return $this->orders;
    }

    public function headings(): array
    {
        return [
            'Receipt #',
            'Date/Time',
            'Cashier',
            'Customer',
            'Items',
            'Payment Method',
            'Discount',
            'Total',
        ];
    }

    /** @param  mixed  $order */
    public function map($order): array
    {
        $items = $order->items->map(fn ($item) => "{$item->name} x{$item->quantity}")->implode(', ');
        $customer = $order->student?->full_name ?? 'Walk-in';

        return [
            $order->receipt_number,
            $order->created_at->format('Y-m-d H:i:s'),
            $order->cashier?->full_name ?? '—',
            $customer,
            $items,
            $order->payment_method?->value ?? '—',
            number_format((float) $order->discount_amount, 2),
            number_format((float) $order->total, 2),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->insertNewRowBefore(1, 1);
        $sheet->setCellValue('A1', "Sales Report — {$this->branchName} — {$this->dateFrom} to {$this->dateTo}");
        $sheet->mergeCells('A1:H1');

        return [
            1 => ['font' => ['bold' => true, 'size' => 13]],
            2 => ['font' => ['bold' => true]],
        ];
    }

    public function title(): string
    {
        return 'Sales Report';
    }
}
