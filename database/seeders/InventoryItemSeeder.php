<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\InventoryItem;
use Illuminate\Database\Seeder;

class InventoryItemSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, quantity: float, unit: string, restock_threshold: float}>
     */
    private array $items = [
        ['name' => 'Rice', 'quantity' => 50, 'unit' => 'kg', 'restock_threshold' => 20],
        ['name' => 'Chicken', 'quantity' => 8, 'unit' => 'kg', 'restock_threshold' => 10],
        ['name' => 'Vegetables', 'quantity' => 15, 'unit' => 'kg', 'restock_threshold' => 5],
        ['name' => 'Bread', 'quantity' => 30, 'unit' => 'pcs', 'restock_threshold' => 20],
        ['name' => 'Juice Boxes', 'quantity' => 3, 'unit' => 'boxes', 'restock_threshold' => 10],
    ];

    public function run(): void
    {
        Branch::all()->each(function (Branch $branch): void {
            foreach ($this->items as $item) {
                InventoryItem::withoutBranch()->updateOrCreate(
                    ['branch_id' => $branch->id, 'name' => $item['name']],
                    ['quantity' => $item['quantity'], 'unit' => $item['unit'], 'restock_threshold' => $item['restock_threshold']],
                );
            }
        });
    }
}
