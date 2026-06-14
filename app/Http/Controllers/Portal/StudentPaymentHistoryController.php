<?php

namespace App\Http\Controllers\Portal;

use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentPaymentHistoryController extends Controller
{
    public function index(Request $request, Student $student): JsonResponse
    {
        $parent = $request->user();

        abort_unless(
            $parent->students()->where('students.id', $student->id)->exists(),
            403,
            'You do not have access to this student.'
        );

        abort_unless(
            $student->student_type === StudentType::Subscription,
            422,
            'Payment history is only available for subscription students.'
        );

        $monthOrder = ['june', 'july', 'august', 'september', 'october', 'november', 'december', 'january', 'february', 'march'];

        $payments = $student->monthlyPayments()
            ->get()
            ->sortBy(fn ($payment) => [$payment->year, array_search($payment->school_month->value, $monthOrder)])
            ->values()
            ->map(fn ($payment) => [
                'id' => $payment->id,
                'school_month' => $payment->school_month->value,
                'year' => $payment->year,
                'amount' => (float) $payment->amount,
                'status' => $payment->status,
                'paid_at' => $payment->recorded_at?->toDateTimeString(),
            ]);

        return response()->json(['data' => $payments]);
    }
}
