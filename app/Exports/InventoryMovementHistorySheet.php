<?php

namespace App\Exports;

use App\Models\InventoryLog;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class InventoryMovementHistorySheet implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles, WithTitle
{
    public function __construct(private readonly Collection $logs) {}

    public function collection(): Collection
    {
        return $this->logs;
    }

    public function headings(): array
    {
        return [
            'Date/Time',
            'Item Name',
            'Type',
            'Change',
            'Stock After',
            'Reason',
            'Adjusted By',
            'Order #',
        ];
    }

    /** @param mixed $log */
    public function map($log): array
    {
        /** @var InventoryLog $log */
        return [
            $log->created_at?->format('Y-m-d H:i:s') ?? '—',
            $log->item_name_snapshot,
            $log->type->label(),
            number_format((float) $log->quantity_change, 2),
            number_format((float) $log->stock_after, 2),
            $log->reason,
            $log->adjustedBy?->full_name ?? '—',
            $log->order_id ?? '—',
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
        return 'Movement History';
    }
}
