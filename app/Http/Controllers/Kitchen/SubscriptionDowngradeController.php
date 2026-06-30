<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class SubscriptionDowngradeController extends Controller
{
    public function preview(Student $student): JsonResponse
    {
        abort_unless(
            $student->student_type === StudentType::Subscription,
            422,
            'Student is not a subscription student.'
        );

        $now = now()->startOfMonth();

        $payments = $student->monthlyPayments()->get();

        $paidRetained = [];
        $paidVoidable = [];
        $unpaidToDelete = [];

        foreach ($payments as $payment) {
            $paymentDate = Carbon::createFromDate(
                $payment->year,
                $payment->school_month->toMonthNumber(),
                1
            )->startOfMonth();

            if ($payment->status === 'paid') {
                if ($paymentDate->lt($now)) {
                    $paidRetained[] = [
                        'id' => $payment->id,
                        'school_month' => $payment->school_month->value,
                        'year' => $payment->year,
                        'amount' => (float) $payment->amount,
                        'label' => $payment->school_month->label().' '.$payment->year,
                    ];
                } else {
                    $paidVoidable[] = [
                        'id' => $payment->id,
                        'school_month' => $payment->school_month->value,
                        'year' => $payment->year,
                        'amount' => (float) $payment->amount,
                        'label' => $payment->school_month->label().' '.$payment->year,
                    ];
                }
            } else {
                $unpaidToDelete[] = $payment->school_month->label().' '.$payment->year;
            }
        }

        $walletBalance = $student->wallet ? (float) $student->wallet->balanceFloatNum : 0.0;

        return response()->json([
            'paid_months_retained' => $paidRetained,
            'paid_voidable_months' => $paidVoidable,
            'unpaid_months_to_delete' => $unpaidToDelete,
            'unpaid_months_to_delete_count' => count($unpaidToDelete),
            'wallet_balance' => $walletBalance,
        ]);
    }
}
