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
        ['name' => 'Juice Tetra Pack', 'quantity' => 48, 'unit' => 'pieces', 'restock_threshold' => 20],
        ['name' => 'Graham Crackers', 'quantity' => 30, 'unit' => 'packs', 'restock_threshold' => 15],
        ['name' => 'Bread Roll', 'quantity' => 24, 'unit' => 'pieces', 'restock_threshold' => 10],
        ['name' => 'Biscuit', 'quantity' => 36, 'unit' => 'packs', 'restock_threshold' => 20],
        ['name' => 'Banana Cue', 'quantity' => 20, 'unit' => 'pieces', 'restock_threshold' => 10],
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
