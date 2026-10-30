<?php

namespace App\Http\Controllers\Kitchen;

use App\Actions\Pos\VoidOrderAction;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ListTransactionsRequest;
use App\Http\Requests\Kitchen\VoidOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;

class TransactionController extends Controller
{
    public function index(ListTransactionsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $date = $validated['date'] ?? now()->toDateString();

        $query = Order::with(['items', 'student.wallet', 'cashier'])
            ->whereDate('created_at', $date)
            ->orderByDesc('created_at');

        if (! empty($validated['payment_method'])) {
            $query->where('payment_method', $validated['payment_method']);
        }

        if (! empty($validated['student_search'])) {
            $search = $validated['student_search'];
            $query->whereHas('student', fn ($q) => $q->whereAnyContainsLiteral(['first_name', 'last_name', 'student_number'], $search));
        }

        $perPage = $validated['per_page'] ?? 25;
        $orders = $query->paginate($perPage);

        $completedOrders = Order::whereDate('created_at', $date)
            ->where('status', OrderStatus::Completed->value)
            ->selectRaw('COUNT(*) as total_transactions, SUM(total) as revenue, SUM(CASE WHEN student_id IS NULL THEN 1 ELSE 0 END) as walk_ins')
            ->first();

        return response()->json([
            'data' => OrderResource::collection($orders->items()),
            'meta' => $this->paginationMeta($orders),
            'summary' => [
                'total_transactions' => (int) ($completedOrders->total_transactions ?? 0),
                'total_revenue' => (float) ($completedOrders->revenue ?? 0),
                'walk_in_count' => (int) ($completedOrders->walk_ins ?? 0),
            ],
        ]);
    }

    #[Authorize('void', 'order')]
    public function void(VoidOrderRequest $request, Order $order, VoidOrderAction $voidOrder): JsonResponse
    {
        $order = $voidOrder->execute($order, $request->user(), $request->validated('void_reason'));

        return response()->json([
            'message' => 'Order voided successfully.',
            'order' => new OrderResource($order),
        ]);
    }
}
