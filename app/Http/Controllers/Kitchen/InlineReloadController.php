<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\InlineReloadRequest;
use App\Models\Student;
use Illuminate\Http\JsonResponse;

class InlineReloadController extends Controller
{
    public function store(InlineReloadRequest $request): JsonResponse
    {
        $validated = $request->validated();

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
