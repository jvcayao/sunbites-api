<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Models\BranchMonthlyAmount;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
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
                'voided_at' => $p->voided_at?->toDateTimeString(),
                'void_reason' => $p->void_reason,
            ]);

        return response()->json($payments);
    }

    public function updateAmount(Request $request, Student $student, StudentMonthlyPayment $payment): JsonResponse
    {
        abort_if($payment->student_id !== $student->id, 403);
        abort_if($payment->isPaid() || $payment->isVoided(), 422, 'Can only edit amount on unpaid payments.');

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
        abort_if($payment->isVoided(), 422, 'Cannot modify a voided payment.');

        $newStatus = $payment->isPaid() ? 'unpaid' : 'paid';

        $payment->update([
            'status' => $newStatus,
            'recorded_at' => $newStatus === 'paid' ? now() : null,
            'recorded_by' => $newStatus === 'paid' ? $request->user()->id : null,
        ]);

        $this->logPaymentActivity($request->user(), $student, $payment, $newStatus);

        return response()->json([
            'id' => $payment->id,
            'year' => $payment->year,
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

        abort_if($payment->isVoided(), 422, 'Cannot record payment on a voided record.');

        $payment->update([
            'status' => 'paid',
            'amount' => $validated['amount'],
            'recorded_at' => now(),
            'recorded_by' => $request->user()->id,
        ]);

        $this->logPaymentActivity($request->user(), $student, $payment, 'paid');

        return response()->json([
            'id' => $payment->id,
            'status' => 'paid',
            'amount' => $payment->amount,
            'recorded_at' => $payment->recorded_at?->toDateTimeString(),
        ]);
    }

    public function void(Request $request, Student $student, StudentMonthlyPayment $payment): JsonResponse
    {
        abort_if($payment->student_id !== $student->id, 404);
        abort_if(! $payment->isPaid(), 422, 'Only paid payments can be voided.');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $paymentMonthStart = Carbon::createFromDate($payment->year, $payment->school_month->toMonthNumber(), 1)->startOfMonth();

        abort_if(
            $paymentMonthStart->lt(now()->startOfMonth()),
            422,
            'Cannot void a past month\'s payment — this subscription period has already been consumed.'
        );

        $payment->update([
            'status' => 'voided',
            'voided_at' => now(),
            'voided_by' => $request->user()->id,
            'void_reason' => $validated['reason'],
        ]);

        activity('payments')
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties([
                'school_month' => $payment->school_month->value,
                'year' => $payment->year,
                'amount' => $payment->amount,
                'reason' => $validated['reason'],
            ])
            ->log('student_payment.voided');

        return response()->json([
            'id' => $payment->id,
            'status' => $payment->status,
            'voided_at' => $payment->voided_at?->toDateTimeString(),
            'void_reason' => $payment->void_reason,
        ]);
    }

    private function logPaymentActivity(User $causer, Student $student, StudentMonthlyPayment $payment, string $status): void
    {
        activity('payments')
            ->causedBy($causer)
            ->performedOn($student)
            ->withProperties([
                'school_month' => $payment->school_month->value,
                'year' => $payment->year,
                'status' => $status,
                'amount' => $payment->amount,
                'recorded_by' => $causer->id,
            ])
            ->log('payments.recorded');
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
                        if ($amount <= 0) {
                            $current->addMonth();

                            continue;
                        }
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
