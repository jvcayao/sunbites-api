<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $student->orders()
            ->whereNull('voided_at')
            ->with('items:id,order_id,name,quantity,price,line_total')
            ->latest();

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        $perPage = $validated['per_page'] ?? 20;
        $totalSpent = (clone $query)->sum('total');
        $orders = $query->paginate($perPage);

        return response()->json([
            'student' => [
                'id' => $student->id,
                'full_name' => $student->full_name,
            ],
            'spending_total' => (float) $totalSpent,
            'data' => collect($orders->items())->map(fn ($order) => [
                'id' => $order->id,
                'receipt_number' => $order->receipt_number,
                'total' => (float) $order->total,
                'payment_method' => $order->payment_method->value,
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'price' => (float) $item->price,
                    'line_total' => (float) $item->line_total,
                ]),
                'created_at' => $order->created_at,
            ]),
            'meta' => $this->paginationMeta($orders),
        ]);
    }
}
