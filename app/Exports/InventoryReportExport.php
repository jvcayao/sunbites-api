<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class InventoryReportExport implements WithMultipleSheets
{
    public function __construct(
        private readonly Collection $items,
        private readonly Collection $logs,
    ) {}

    public function sheets(): array
    {
        return [
            new InventoryCurrentStockSheet($this->items),
            new InventoryMovementHistorySheet($this->logs),
        ];
    }
}
