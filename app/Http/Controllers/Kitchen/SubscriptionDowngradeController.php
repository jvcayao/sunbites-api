<?php

namespace App\Http\Controllers\Kitchen;

use App\Actions\DowngradeStudentSubscriptionAction;
use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionDowngradeController extends Controller
{
    public function preview(Student $student): JsonResponse
    {
        abort_unless($student->student_type === StudentType::Subscription, 422, 'Student is not a subscription student.');

        $currentMonthStart = now()->startOfMonth();
        $payments = $student->monthlyPayments()->get();

        $paidRetained = [];
        $paidVoidable = [];
        $unpaidToDelete = [];

        foreach ($payments as $payment) {
            if ($payment->status === 'paid') {
                $paymentMonthStart = Carbon::createFromDate($payment->year, $payment->school_month->toMonthNumber(), 1)->startOfMonth();

                if ($paymentMonthStart->lt($currentMonthStart)) {
                    $paidRetained[] = $this->paymentShape($payment);
                } else {
                    $paidVoidable[] = $this->paymentShape($payment);
                }
            } else {
                $unpaidToDelete[] = $payment->school_month->label().' '.$payment->year;
            }
        }

        return response()->json([
            'paid_months_retained' => $paidRetained,
            'paid_voidable_months' => $paidVoidable,
            'unpaid_months_to_delete' => $unpaidToDelete,
            'unpaid_months_to_delete_count' => count($unpaidToDelete),
            'wallet_balance' => (float) ($student->wallet?->balanceFloatNum ?? 0.0),
        ]);
    }

    public function execute(Request $request, Student $student, DowngradeStudentSubscriptionAction $action): JsonResponse
    {
        abort_unless($student->student_type === StudentType::Subscription, 422, 'Student is not a subscription student.');

        return response()->json(new StudentResource($action->execute($student, $request->user())));
    }

    private function paymentShape(StudentMonthlyPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'school_month' => $payment->school_month->value,
            'year' => $payment->year,
            'amount' => (float) $payment->amount,
            'label' => $payment->school_month->label().' '.$payment->year,
        ];
    }
}
