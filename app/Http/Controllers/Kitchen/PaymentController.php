<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function toggle(Request $request, Student $student, StudentMonthlyPayment $payment): RedirectResponse
    {
        abort_if($payment->student_id !== $student->id, 403);

        $newStatus = $payment->status === 'paid' ? 'unpaid' : 'paid';

        $payment->update([
            'status' => $newStatus,
            'recorded_at' => $newStatus === 'paid' ? now() : null,
            'recorded_by' => $newStatus === 'paid' ? $request->user()->id : null,
        ]);

        activity('payments')
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties([
                'school_month' => $payment->school_month->value,
                'status' => $newStatus,
                'amount' => $payment->amount,
                'recorded_by' => $request->user()->id,
            ])
            ->log('payments.recorded');

        return back()->with('success', 'Payment status updated.');
    }

    public function record(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'school_month' => ['required', Rule::enum(SchoolMonth::class)],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $payment = StudentMonthlyPayment::where('student_id', $student->id)
            ->where('school_month', $validated['school_month'])
            ->firstOrFail();

        $payment->update([
            'status' => 'paid',
            'amount' => $validated['amount'],
            'recorded_at' => now(),
            'recorded_by' => $request->user()->id,
        ]);

        activity('payments')
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties([
                'school_month' => $validated['school_month'],
                'status' => 'paid',
                'amount' => $validated['amount'],
                'recorded_by' => $request->user()->id,
            ])
            ->log('payments.recorded');

        return back()->with('success', 'Payment recorded.');
    }
}
