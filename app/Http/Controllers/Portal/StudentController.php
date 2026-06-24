<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $students = $request->user()
            ->students()
            ->with(['branch:id,name,slug', 'wallet'])
            ->get()
            ->map(fn ($student) => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'full_name' => $student->full_name,
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'grade_level' => $student->grade_level,
                'section' => $student->section,
                'birthday' => $student->birthday?->format('Y-m-d'),
                'notes' => $student->notes,
                'qr_code' => $student->qr_code,
                'photo_url' => $student->photo_path
                    ? url("/api/v1/portal/students/{$student->id}/photo")
                    : null,
                'student_type' => $student->student_type->value,
                'enrollment_status' => $student->enrollment_status->value,
                'allergies' => $student->allergies,
                'branch_name' => $student->branch->name,
                'wallet_balance' => $student->wallet?->balanceFloatNum ?? 0.0,
                'wallet_alert_threshold' => (float) $student->pivot->wallet_alert_threshold,
                'linked_at' => $student->pivot->linked_at,
                'subscription_monthly_status' => $student->currentMonthSubscriptionStatus(),
            ]);

        return response()->json(['data' => $students]);
    }
}
