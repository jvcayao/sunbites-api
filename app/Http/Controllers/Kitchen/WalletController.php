<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function topUp(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'payment_method' => ['required', 'in:cash,gcash,bank_transfer'],
            'reference_number' => [
                'nullable',
                'string',
                'alpha_num',
                'max:50',
            ],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $note = isset($validated['note']) ? strip_tags($validated['note']) : null;

        $student->deposit((int) round($validated['amount'] * 100), [
            'payment_method' => $validated['payment_method'],
            'reference_number' => $validated['reference_number'] ?? null,
            'note' => $note,
            'performed_by' => $request->user()->id,
        ]);

        activity('wallet')
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties([
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference_number'] ?? null,
                'new_balance' => $student->fresh()->balance / 100,
            ])
            ->log('wallet.topped_up');

        return back()->with('success', 'Wallet topped up successfully.');
    }
}
