<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\PosMenuItem;
use Illuminate\Database\Seeder;

class PosMenuItemSeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, price: float, category: string}>
     */
    private array $items = [
        ['name' => 'Subscription Meal Tray', 'price' => 135.00, 'category' => 'meal'],
        ['name' => 'Snack A (Bread/Pastry)', 'price' => 15.00, 'category' => 'snack'],
        ['name' => 'Snack B (Chips/Crackers)', 'price' => 20.00, 'category' => 'snack'],
        ['name' => 'Snack C (Juice/Water)', 'price' => 15.00, 'category' => 'drink'],
        ['name' => 'Snack D (Fruit Cup)', 'price' => 25.00, 'category' => 'snack'],
        ['name' => 'Additional Rice', 'price' => 10.00, 'category' => 'extra'],
        ['name' => 'Special Snack', 'price' => 30.00, 'category' => 'snack'],
    ];

    public function run(): void
    {
        Branch::all()->each(function (Branch $branch): void {
            foreach ($this->items as $index => $item) {
                PosMenuItem::withoutBranch()->updateOrCreate(
                    ['branch_id' => $branch->id, 'name' => $item['name']],
                    ['price' => $item['price'], 'category' => $item['category'], 'sort_order' => $index],
                );
            }
        });
    }
}
