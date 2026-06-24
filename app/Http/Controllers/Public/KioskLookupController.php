<?php

namespace App\Http\Controllers\Public;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\KioskLookupRequest;
use App\Models\Order;
use App\Models\Student;
use Illuminate\Http\JsonResponse;

class KioskLookupController extends Controller
{
    public function lookup(KioskLookupRequest $request): JsonResponse
    {
        $student = Student::withoutBranch()
            ->with('wallet')
            ->where('qr_code', $request->validated('qr_code'))
            ->first();

        if (! $student) {
            return response()->json(['message' => 'Student not found.'], 404);
        }

        if ($student->enrollment_status !== EnrollmentStatus::Enrolled) {
            return response()->json(['message' => 'Student is not eligible.'], 403);
        }

        $lastOrders = Order::withoutBranch()
            ->where('student_id', $student->id)
            ->with('items')
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Order $order) => [
                'items' => $order->items->pluck('name')->join(', '),
                'total' => number_format($order->items->sum('line_total'), 2),
                'date' => $order->created_at->format('M j, Y'),
            ]);

        $initials = mb_strtoupper(mb_substr($student->first_name, 0, 1).mb_substr($student->last_name, 0, 1));

        return response()->json([
            'name' => $student->full_name,
            'initials' => $initials,
            'grade_level' => $student->grade_level,
            'student_type' => $student->student_type->value,
            'balance' => number_format($student->wallet?->balanceFloatNum ?? 0.0, 2),
            'last_orders' => $lastOrders,
        ]);
    }
}
