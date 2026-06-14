<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\EnrollmentStatus;
use App\Enums\MenuCategory;
use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Models\BranchSubscriptionConfig;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentLookupController extends Controller
{
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:qr,search'],
            'value' => ['required', 'string', 'max:255'],
        ]);

        return match ($validated['type']) {
            'qr' => $this->lookupByQr($validated['value']),
            'search' => $this->lookupBySearch($validated['value']),
        };
    }

    private function lookupByQr(string $qrCode): JsonResponse
    {
        $student = Student::with('wallet')
            ->where('qr_code', $qrCode)
            ->first();

        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        if ($student->enrollment_status !== EnrollmentStatus::Enrolled) {
            return response()->json([
                'message' => 'Student is not eligible.',
                'error' => 'not_enrolled',
                'student' => $this->minimalStudentData($student),
            ], 422);
        }

        return response()->json([
            'student' => $this->fullStudentData($student),
        ]);
    }

    public function show(Student $student): JsonResponse
    {
        $student->loadMissing('wallet');

        return response()->json([
            'student' => $this->fullStudentData($student),
        ]);
    }

    private function lookupBySearch(string $query): JsonResponse
    {
        $students = Student::where(function ($q) use ($query) {
            $q->where('first_name', 'like', "%{$query}%")
                ->orWhere('last_name', 'like', "%{$query}%")
                ->orWhere('student_number', 'like', "%{$query}%");
        })
            ->orderBy('last_name')
            ->limit(8)
            ->get();

        return response()->json([
            'students' => $students->map(fn (Student $student) => $this->minimalStudentData($student)),
        ]);
    }

    /** @return array<string, mixed> */
    private function minimalStudentData(Student $student): array
    {
        return [
            'id' => $student->id,
            'full_name' => $student->full_name,
            'student_number' => $student->student_number,
            'grade_level' => $student->grade_level,
            'section' => $student->section,
            'has_photo' => (bool) $student->photo_path,
            'enrollment_status' => $student->enrollment_status?->value,
            'enrollment_status_label' => $student->enrollment_status?->label(),
        ];
    }

    /** @return array<string, mixed> */
    private function fullStudentData(Student $student): array
    {
        return [
            'id' => $student->id,
            'full_name' => $student->full_name,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'student_number' => $student->student_number,
            'grade_level' => $student->grade_level,
            'section' => $student->section,
            'has_photo' => (bool) $student->photo_path,
            'student_type' => $student->student_type?->value,
            'student_type_label' => $student->student_type?->label(),
            'enrollment_status' => $student->enrollment_status?->value,
            'enrollment_status_label' => $student->enrollment_status?->label(),
            'points' => $student->points,
            'total_spent' => $student->total_spent,
            'credit_balance' => $student->credit_balance,
            'wallet_balance' => $student->wallet?->balanceFloatNum ?? 0.0,
            'subscription_daily_status' => $student->student_type === StudentType::Subscription
                ? $this->buildDailyStatus($student)
                : null,
            'subscription_monthly_status' => $student->currentMonthSubscriptionStatus(),
        ];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function buildDailyStatus(Student $student): array
    {
        $todayUsed = $student->todaySubscriptionUsageByCategory();

        $config = BranchSubscriptionConfig::forBranch($student->branch_id);

        $result = [];
        foreach (MenuCategory::cases() as $category) {
            $used = (int) ($todayUsed[$category->value] ?? 0);
            $limit = $config->limitForCategory($category);
            $result[$category->value] = [
                'used' => $used,
                'limit' => $limit,
                'remaining' => max(0, $limit - $used),
            ];
        }

        return $result;
    }
}
