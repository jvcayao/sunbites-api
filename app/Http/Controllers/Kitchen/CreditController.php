<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\CreditTransactionType;
use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditController extends Controller
{
    public function settle(Request $request, Student $student): RedirectResponse
    {
        if ($student->credit_balance <= 0) {
            return back()->withErrors(['credit' => 'No outstanding credit to settle.']);
        }

        $amountSettled = DB::transaction(function () use ($request, $student): float {
            /** @var Student $locked */
            $locked = Student::lockForUpdate()->findOrFail($student->id);

            if ($locked->credit_balance <= 0) {
                return 0.0;
            }

            $amount = (float) $locked->credit_balance;

            CreditTransaction::create([
                'student_id' => $locked->id,
                'type' => CreditTransactionType::Settled->value,
                'amount' => $amount,
                'notes' => 'Credit settled manually.',
                'performed_by' => $request->user()->id,
                'created_at' => now(),
            ]);

            $locked->update(['credit_balance' => 0]);

            return $amount;
        });

        if ($amountSettled === 0.0) {
            return back()->withErrors(['credit' => 'No outstanding credit to settle.']);
        }

        activity('wallet')
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties([
                'amount_settled' => $amountSettled,
                'settled_by' => $request->user()->id,
            ])
            ->log('wallet.credit_settled');

        return back()->with('success', 'Credit balance settled.');
    }
}
