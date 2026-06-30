<?php

namespace Database\Seeders;

use App\Enums\SchoolMonth;
use App\Enums\StudentType;
use App\Models\Branch;
use App\Models\BranchMonthlyAmount;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
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
        $contactRows = [];
        $now = now()->toDateTimeString();
        $parentIndex = 0;

        foreach ($soloIds as $studentId) {
            $parent = $parents[$parentIndex];
            $pivotRows[] = [
                'student_id' => $studentId,
                'parent_id' => $parent->id,
                'linked_at' => $now,
                'linked_by' => $staffUser->id,
                'wallet_alert_threshold' => fake()->randomElement([0, 50, 100, 150, 200]),
            ];
            $contactRows[] = [
                'student_id' => $studentId,
                'full_name' => $parent->first_name.' '.$parent->last_name,
                'relationship' => 'Parent',
                'phone' => fake()->phoneNumber(),
                'address' => fake()->address(),
                'email' => $parent->email,
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $parentIndex++;
        }

        foreach ($siblingIds as $pair) {
            $parent = $parents[$parentIndex];
            $parentId = $parent->id;
            foreach ($pair as $studentId) {
                $pivotRows[] = [
                    'student_id' => $studentId,
                    'parent_id' => $parentId,
                    'linked_at' => $now,
                    'linked_by' => $staffUser->id,
                    'wallet_alert_threshold' => fake()->randomElement([0, 50, 100, 150, 200]),
                ];
                $contactRows[] = [
                    'student_id' => $studentId,
                    'full_name' => $parent->first_name.' '.$parent->last_name,
                    'relationship' => 'Parent',
                    'phone' => fake()->phoneNumber(),
                    'address' => fake()->address(),
                    'email' => $parent->email,
                    'is_primary' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $parentIndex++;
        }

        // Bulk insert all pivot rows — one query instead of 300 attach() calls
        foreach (array_chunk($pivotRows, 500) as $chunk) {
            DB::table('parent_student')->insert($chunk);
        }

        // Bulk insert contact records so the POS Contacts tab shows linked parents
        foreach (array_chunk($contactRows, 500) as $chunk) {
            DB::table('student_contacts')->insert($chunk);
        }

        // Seed a known test parent for local login (email: parent@sunbites.test / Password1)
        $testParent = ParentUser::factory()->create([
            'first_name' => 'Test',
            'last_name' => 'Parent',
            'email' => 'parent@sunbites.test',
        ]);
        $testStudent = $students->first();
        DB::table('parent_student')->insert([
            'student_id' => $testStudent->id,
            'parent_id' => $testParent->id,
            'linked_at' => $now,
            'linked_by' => $staffUser->id,
            'wallet_alert_threshold' => 100,
        ]);
        DB::table('student_contacts')->insert([
            'student_id' => $testStudent->id,
            'full_name' => 'Test Parent',
            'relationship' => 'Parent',
            'phone' => '09170000000',
            'address' => '1 Test Street',
            'email' => 'parent@sunbites.test',
            'is_primary' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->seedSubscriptionPayments($students);

        $subscriptionCount = $students->filter(
            fn (Student $s) => $s->student_type === StudentType::Subscription
        )->count();

        $this->command->info(sprintf(
            'Seeded %d students (%d subscription), %d parents, %d parent-student links.',
            self::STUDENT_COUNT,
            $subscriptionCount,
            $parentCount,
            count($pivotRows),
        ));
        $this->command->info('Test parent login → email: parent@sunbites.test / password: Password1');
    }

    private function seedSubscriptionPayments(Collection $students): void
    {
        $startMonth = $this->nextSchoolMonth();
        $startDate = Carbon::createFromDate($startMonth['year'], $startMonth['month']->toMonthNumber(), 1);

        $rows = [];

        foreach ($students as $student) {
            if ($student->student_type !== StudentType::Subscription) {
                continue;
            }

            $targetMonths = fake()->numberBetween(1, 9);
            $current = $startDate->copy();
            $seeded = 0;
            $iterated = 0;

            while ($seeded < $targetMonths && $iterated < 24) {
                $schoolMonth = SchoolMonth::fromMonthNumber($current->month);

                if ($schoolMonth !== null) {
                    $amount = BranchMonthlyAmount::resolveAmount($student->branch_id, $schoolMonth, $current->year);

                    if ($amount > 0) {
                        $rows[] = [
                            'student_id' => $student->id,
                            'school_month' => $schoolMonth->value,
                            'year' => $current->year,
                            'status' => 'unpaid',
                            'amount' => $amount,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                        $seeded++;
                    }
                }

                $current->addMonth();
                $iterated++;
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('student_monthly_payments')->insert($chunk);
        }
    }

    /** @return array{month: SchoolMonth, year: int} */
    private function nextSchoolMonth(): array
    {
        $candidate = now()->addMonth()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $schoolMonth = SchoolMonth::fromMonthNumber($candidate->month);
            if ($schoolMonth !== null) {
                return ['month' => $schoolMonth, 'year' => $candidate->year];
            }
            $candidate->addMonth();
        }

        return ['month' => SchoolMonth::June, 'year' => now()->year];
    }
}
