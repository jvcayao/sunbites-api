<?php

namespace App\Services;

use App\Enums\SchoolMonth;
use App\Enums\StudentType;
use App\Models\BranchMonthlyAmount;
use App\Models\Student;
use Carbon\Carbon;

class EnrollmentService
{
    /**
     * Create a student record and seed monthly payments for subscription students.
     *
     * Callers are responsible for: contact creation, parent provisioning, activity logging.
     * This method must be called inside a DB::transaction().
     *
     * @param  array{
     *     branch_id: int,
     *     student_number: string|null,
     *     first_name: string,
     *     last_name: string,
     *     grade_level: string,
     *     section: string|null,
     *     birthday: string,
     *     student_type: string,
     *     photo_path: string|null,
     *     allergies: string|null,
     *     notes: string|null,
     *     subscription_start_month: string|null,
     *     subscription_start_year: int|null,
     *     subscription_end_month: string|null,
     *     subscription_end_year: int|null,
     * }  $data
     */
    public function enroll(array $data): Student
    {
        $qrCode = Student::generateUniqueQrCode();

        $student = Student::create([
            'branch_id' => $data['branch_id'],
            'student_number' => $data['student_number'] ?? null,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'grade_level' => $data['grade_level'],
            'section' => $data['section'] ?? null,
            'birthday' => $data['birthday'],
            'photo_path' => $data['photo_path'] ?? null,
            'allergies' => $data['allergies'] ?? null,
            'notes' => $data['notes'] ?? null,
            'qr_code' => $qrCode,
            'student_type' => $data['student_type'],
            'enrollment_status' => 'enrolled',
            'enrollment_date' => now()->toDateString(),
        ]);

        if (StudentType::from($data['student_type']) === StudentType::Subscription) {
            $this->seedMonthlyPayments($student, $data);
        }

        return $student;
    }

    private function seedMonthlyPayments(Student $student, array $data): void
    {
        $startYear = (int) $data['subscription_start_year'];
        $endYear = (int) $data['subscription_end_year'];
        $startMonth = SchoolMonth::from($data['subscription_start_month']);
        $endMonth = SchoolMonth::from($data['subscription_end_month']);

        $start = Carbon::createFromDate($startYear, $startMonth->toMonthNumber(), 1);
        $end = Carbon::createFromDate($endYear, $endMonth->toMonthNumber(), 1);

        $current = $start->copy();
        while ($current->lte($end)) {
            $schoolMonth = SchoolMonth::fromMonthNumber($current->month);
            if ($schoolMonth !== null) {
                $year = $current->year;
                $amount = BranchMonthlyAmount::resolveAmount($student->branch_id, $schoolMonth, $year);
                if ($amount == 0) {
                    $current->addMonth();

                    continue;
                }
                $student->monthlyPayments()->create([
                    'school_month' => $schoolMonth->value,
                    'year' => $year,
                    'status' => 'unpaid',
                    'amount' => $amount,
                ]);
            }
            $current->addMonth();
        }
    }
}
