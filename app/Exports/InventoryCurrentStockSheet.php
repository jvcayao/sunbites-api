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

class InventoryCurrentStockSheet implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private readonly Collection $items) {}

    public function collection(): Collection
    {
        return $this->items;
    }

    public function headings(): array
    {
        return [
            'Item Name',
            'Unit',
            'Current Qty',
            'Restock Threshold',
            'Overstock Threshold',
            'Cost/Unit',
            'Status',
        ];
    }

    /** @param mixed $item */
    public function map($item): array
    {
        return [
            $item->name,
            $item->unit,
            number_format((float) $item->quantity, 2),
            number_format((float) $item->restock_threshold, 2),
            $item->overstock_threshold !== null ? number_format((float) $item->overstock_threshold, 2) : '—',
            $item->cost_per_unit !== null ? number_format((float) $item->cost_per_unit, 2) : '—',
            $item->status,
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
        return 'Current Stock';
    }
}
