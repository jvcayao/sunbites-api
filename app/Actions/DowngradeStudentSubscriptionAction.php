<?php

namespace App\Actions;

use App\Enums\StudentType;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DowngradeStudentSubscriptionAction
{
    public function execute(Student $student, User $causer): Student
    {
        return DB::transaction(function () use ($student, $causer): Student {
            $unpaidPayments = $student->monthlyPayments()
                ->where('status', 'unpaid')
                ->get();

            $deletedMonthLabels = $unpaidPayments
                ->map(fn ($p) => $p->school_month->label().' '.$p->year)
                ->values()
                ->all();

            $paidMonthLabels = $student->monthlyPayments()
                ->where('status', 'paid')
                ->get()
                ->map(fn ($p) => $p->school_month->label().' '.$p->year)
                ->values()
                ->all();

            StudentMonthlyPayment::whereIn('id', $unpaidPayments->pluck('id'))->delete();

            $student->update(['student_type' => StudentType::NonSubscription]);

            activity('students')
                ->causedBy($causer)
                ->performedOn($student)
                ->withProperties([
                    'deleted_months' => $deletedMonthLabels,
                    'deleted_count' => count($deletedMonthLabels),
                    'paid_months_retained' => $paidMonthLabels,
                    'note' => 'Unpaid monthly payments were removed. Paid months are retained for history.',
                ])
                ->log('students.downgraded_to_non_subscription');

            return $student->fresh();
        });
    }
}
