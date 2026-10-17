<?php

namespace App\Http\Controllers\Portal;

use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Authorize;

class StudentPaymentHistoryController extends Controller
{
    #[Authorize('view', 'student')]
    public function index(Request $request, Student $student): JsonResponse
    {
        $monthOrder = array_column(SchoolMonth::cases(), 'value');

        $payments = $student->monthlyPayments()
            ->where('status', '!=', 'voided')
            ->orderBy('year')
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
