<?php

namespace App\Http\Controllers\Kitchen;

use App\Actions\Students\EnrollStudentAction;
use App\Enums\SchoolMonth;
use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\EnrollStudentRequest;
use App\Models\Branch;
use App\Models\SystemConfiguration;
use Illuminate\Http\JsonResponse;

class EnrollmentController extends Controller
{
    public function index(): JsonResponse
    {
        $dailyRate = SystemConfiguration::getValue('daily_meal_rate', 135);
        $configMonths = config('sunbites.school_months');

        $defaults = collect(SchoolMonth::cases())->mapWithKeys(function (SchoolMonth $m) use ($configMonths, $dailyRate) {
            $days = $configMonths[$m->value]['days'] ?? 0;

            return [
                $m->value => [
                    'month' => $m->value,
                    'label' => $m->label(),
                    'days' => $days,
                    'default_amount' => $days * $dailyRate,
                ],
            ];
        });

        return response()->json([
            'branches' => Branch::where('is_active', true)->get(['id', 'name', 'slug']),
            'grade_levels' => config('sunbites.grade_levels'),
            'school_month_defaults' => $defaults,
        ]);
    }

    public function store(EnrollStudentRequest $request, EnrollStudentAction $enroll): JsonResponse
    {
        $validated = $request->validated();

        $studentType = StudentType::from($validated['student_type']);

        $validated['allergies'] = isset($validated['allergies']) ? strip_tags($validated['allergies']) : null;
        $validated['notes'] = isset($validated['notes']) ? strip_tags($validated['notes']) : null;

        $student = $enroll->execute($validated, $request->hasFile('photo') ? $request->file('photo') : null, $request->user());

        $subscriptionPeriod = null;
        if ($studentType === StudentType::Subscription) {
            $startLabel = SchoolMonth::from($validated['subscription_start_month'])->label().' '.$validated['subscription_start_year'];
            $endLabel = SchoolMonth::from($validated['subscription_end_month'])->label().' '.$validated['subscription_end_year'];
            $subscriptionPeriod = $startLabel.' – '.$endLabel;
        }

        return response()->json([
            'id' => $student->id,
            'student_number' => $student->student_number,
            'qr_code' => $student->qr_code,
            'full_name' => $student->full_name,
            'student_type' => $student->student_type->value,
            'enrollment_date' => $student->enrollment_date->toDateString(),
            'subscription_period' => $subscriptionPeriod,
        ], 201);
    }
}
