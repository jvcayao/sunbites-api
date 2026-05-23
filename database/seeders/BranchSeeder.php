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
                'gcash_number' => '09074984172',
                'is_active' => true,
            ],
            [
                'name' => 'Iloilo Branch',
                'slug' => 'iloilo',
                'gcash_number' => '09922761801',
                'is_active' => true,
            ],
        ];

        foreach ($branches as $data) {
            Branch::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
