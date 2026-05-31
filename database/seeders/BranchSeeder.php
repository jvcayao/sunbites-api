<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            [
                'name' => 'Antipolo Branch',
                'slug' => 'antipolo',
                'gcash_number' => '+63 907 498 4172',
                'is_active' => true,
            ],
            [
                'name' => 'Iloilo Branch',
                'slug' => 'iloilo',
                'gcash_number' => '+63 907 498 4172',
                'is_active' => true,
            ],
        ];

        foreach ($branches as $data) {
            Branch::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
