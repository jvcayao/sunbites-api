<?php

namespace App\Enums;

enum InventoryLogType: string
{
    case Restock = 'restock';
    case Waste = 'waste';
    case Manual = 'manual';
    case Sale = 'sale';

    public function label(): string
    {
        return match ($this) {
            self::Restock => 'Restocked',
            self::Waste => 'Wasted',
            self::Manual => 'Manual Correction',
            self::Sale => 'Sale',
        };
    }
}
