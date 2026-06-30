<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StudentWithParentsSeeder extends Seeder
{
    private const STUDENT_COUNT = 300;

    public function run(): void
    {
        $this->call(BranchSeeder::class);

        $branches = Branch::all();
        $staffUser = User::first() ?? User::factory()->create();

        // Distribute students evenly across seeded branches
        $students = collect();
        $perBranch = (int) ceil(self::STUDENT_COUNT / $branches->count());

        foreach ($branches as $branch) {
            $students = $students->merge(
                Student::factory()
                    ->count($perBranch)
                    ->create(['branch_id' => $branch->getKey()])
            );
        }

        $students = $students->take(self::STUDENT_COUNT);

        // Build family groups: ~220 parents cover 300 students
        // ~80 parents each claim 2 students (siblings), rest get 1
        $studentIds = $students->pluck('id')->toArray();
        shuffle($studentIds);

        // First 160 students get a dedicated parent; remaining 140 are split into 70 sibling pairs
        $soloIds = array_slice($studentIds, 0, 160);
        $siblingIds = array_chunk(array_slice($studentIds, 160), 2);

        $parentCount = count($soloIds) + count($siblingIds);
        $parents = ParentUser::factory()->count($parentCount)->create()->values();

        $pivotRows = [];
        $now = now()->toDateTimeString();
        $parentIndex = 0;

        foreach ($soloIds as $studentId) {
            $pivotRows[] = [
                'student_id' => $studentId,
                'parent_id' => $parents[$parentIndex]->id,
                'linked_at' => $now,
                'linked_by' => $staffUser->id,
                'wallet_alert_threshold' => fake()->randomElement([0, 50, 100, 150, 200]),
            ];
            $parentIndex++;
        }

        foreach ($siblingIds as $pair) {
            $parentId = $parents[$parentIndex]->id;
            foreach ($pair as $studentId) {
                $pivotRows[] = [
                    'student_id' => $studentId,
                    'parent_id' => $parentId,
                    'linked_at' => $now,
                    'linked_by' => $staffUser->id,
                    'wallet_alert_threshold' => fake()->randomElement([0, 50, 100, 150, 200]),
                ];
            }
            $parentIndex++;
        }

        // Bulk insert all pivot rows — one query instead of 300 attach() calls
        foreach (array_chunk($pivotRows, 500) as $chunk) {
            DB::table('parent_student')->insert($chunk);
        }

        $this->command->info(sprintf(
            'Seeded %d students, %d parents, %d parent-student links.',
            self::STUDENT_COUNT,
            $parentCount,
            count($pivotRows),
        ));
    }
}
