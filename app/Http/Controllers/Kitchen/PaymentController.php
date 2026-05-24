<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Models\BranchMonthlyAmount;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Student $student): JsonResponse
    {
        $payments = $student->monthlyPayments()
            ->orderBy('year')
            ->orderBy('id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'school_month' => $p->school_month?->value,
                'school_month_label' => $p->school_month?->label(),
                'year' => $p->year,
                'status' => $p->status,
                'amount' => $p->amount,
                'recorded_at' => $p->recorded_at?->toDateTimeString(),
            ]);

        return response()->json($payments);
    }

    public function updateAmount(Request $request, Student $student, StudentMonthlyPayment $payment): JsonResponse
    {
        abort_if($payment->student_id !== $student->id, 403);
        abort_if($payment->status !== 'unpaid', 422, 'Can only edit amount on unpaid payments.');

        $validated = $request->validate(['amount' => ['required', 'numeric', 'min:0']]);
        $payment->update(['amount' => $validated['amount']]);

        return response()->json([
            'id' => $payment->id,
            'status' => $payment->status,
            'amount' => $payment->amount,
        ]);
    }

    public function toggle(Request $request, Student $student, StudentMonthlyPayment $payment): JsonResponse
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
                'year' => $payment->year,
                'status' => $newStatus,
                'amount' => $payment->amount,
                'recorded_by' => $request->user()->id,
            ])
            ->log('payments.recorded');

        return response()->json([
            'id' => $payment->id,
            'status' => $newStatus,
            'recorded_at' => $payment->recorded_at?->toDateTimeString(),
        ]);
    }

    public function record(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'school_month' => ['required', Rule::enum(SchoolMonth::class)],
            'year' => ['required', 'integer', 'digits:4'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $payment = StudentMonthlyPayment::where('student_id', $student->id)
            ->where('school_month', $validated['school_month'])
            ->where('year', $validated['year'])
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
                'year' => $validated['year'],
                'status' => 'paid',
                'amount' => $validated['amount'],
                'recorded_by' => $request->user()->id,
            ])
            ->log('payments.recorded');

        return response()->json([
            'id' => $payment->id,
            'status' => 'paid',
            'amount' => $payment->amount,
            'recorded_at' => $payment->recorded_at?->toDateTimeString(),
        ]);
    }

    public function addRange(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'subscription_start_month' => ['required', Rule::enum(SchoolMonth::class)],
            'subscription_start_year' => ['required', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'subscription_end_month' => ['required', Rule::enum(SchoolMonth::class)],
            'subscription_end_year' => ['required', 'integer', 'digits:4', 'min:2020', 'max:2099'],
        ]);

        $start = Carbon::createFromDate(
            $validated['subscription_start_year'],
            SchoolMonth::from($validated['subscription_start_month'])->toMonthNumber(),
            1
        );
        $end = Carbon::createFromDate(
            $validated['subscription_end_year'],
            SchoolMonth::from($validated['subscription_end_month'])->toMonthNumber(),
            1
        );

        if ($end->lt($start)) {
            return response()->json(['errors' => ['subscription_end_month' => ['End month must be after start month.']]], 422);
        }

        ['created' => $created, 'skipped' => $skipped] = DB::transaction(function () use ($start, $end, $student): array {
            $created = [];
            $skipped = [];
            $current = $start->copy();

            while ($current->lte($end)) {
                $schoolMonth = SchoolMonth::fromMonthNumber($current->month);

                if ($schoolMonth !== null) {
                    $year = $current->year;
                    $exists = StudentMonthlyPayment::where('student_id', $student->id)
                        ->where('school_month', $schoolMonth->value)
                        ->where('year', $year)
                        ->exists();

                    if ($exists) {
                        $skipped[] = $schoolMonth->label().' '.$year;
                    } else {
                        $amount = BranchMonthlyAmount::resolveAmount($student->branch_id, $schoolMonth, $year);
                        $created[] = $student->monthlyPayments()->create([
                            'school_month' => $schoolMonth->value,
                            'year' => $year,
                            'status' => 'unpaid',
                            'amount' => $amount,
                        ]);
                    }
                }

                $current->addMonth();
            }

            return compact('created', 'skipped');
        });

        return response()->json([
            'created' => count($created),
            'skipped' => $skipped,
            'payments' => $created,
        ], 201);
    }
}
