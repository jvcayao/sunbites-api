<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            BranchSeeder::class,
            PosMenuItemSeeder::class,
            WeeklyMealPlanSeeder::class,
            InventoryItemSeeder::class,
        ]);
    }
}
