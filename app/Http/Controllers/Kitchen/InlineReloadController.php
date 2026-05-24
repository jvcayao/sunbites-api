<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InlineReloadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'payment_method' => ['required', 'in:cash,gcash'],
            'reference_number' => ['nullable', 'string', 'alpha_num', 'max:50'],
            'order_context' => ['nullable', 'string', 'max:255'],
        ]);

        $student = Student::findOrFail($validated['student_id']);

        $student->deposit((int) round($validated['amount'] * 100), [
            'source' => 'pos_inline_reload',
            'payment_method' => $validated['payment_method'],
            'reference_number' => $validated['reference_number'] ?? null,
            'cashier_id' => $request->user()->id,
            'order_context' => $validated['order_context'] ?? null,
        ]);

        $student->load('wallet');

        activity('wallet')
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties([
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'cashier_id' => $request->user()->id,
                'order_context' => $validated['order_context'] ?? null,
            ])
            ->log('wallet.inline_reload');

        return response()->json([
            'message' => 'Wallet reloaded successfully.',
            'new_balance' => (float) ($student->wallet?->balanceFloat ?? 0),
        ]);
    }
}
