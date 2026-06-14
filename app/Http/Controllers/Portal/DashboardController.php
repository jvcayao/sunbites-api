<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $parent = $request->user();

        $students = $parent->students()->with(['branch:id,name', 'wallet'])->get();

        $studentIds = $students->pluck('id');

        $recentOrders = Order::whereIn('student_id', $studentIds)
            ->whereNull('voided_at')
            ->with('student:id,first_name,last_name')
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'students' => $students->map(fn ($student) => [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'student_number' => $student->student_number,
                'grade_level' => $student->grade_level,
                'branch_name' => $student->branch?->name,
                'wallet_balance' => $student->wallet?->balanceFloatNum ?? 0.0,
                'wallet_alert_threshold' => (float) $student->pivot->wallet_alert_threshold,
                'enrollment_status' => $student->enrollment_status,
                'student_type' => $student->student_type,
            ]),
            'recent_orders' => $recentOrders->map(fn ($order) => [
                'id' => $order->id,
                'student_full_name' => $order->student?->full_name,
                'total' => (float) $order->total,
                'payment_method' => $order->payment_method,
                'created_at' => $order->created_at,
            ]),
        ]);
    }
}
