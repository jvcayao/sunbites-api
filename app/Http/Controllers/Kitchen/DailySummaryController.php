<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailySummaryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        $branchId = app('active_branch')->id;
        $date = $validated['date'] ?? now()->toDateString();

        $baseQuery = fn () => Order::withoutBranch()
            ->where('branch_id', $branchId)
            ->where('status', OrderStatus::Completed)
            ->whereDate('created_at', $date);

        $totalOrders = $baseQuery()->count();

        $paymentBreakdown = $baseQuery()
            ->selectRaw('payment_method, COUNT(*) as count, SUM(total) as total')
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->payment_method->value,
                'count' => (int) $row->count,
                'amount' => (float) $row->total,
            ])
            ->values();

        $totals = $baseQuery()
            ->selectRaw('SUM(discount_amount) as total_discounts, SUM(total) as total_revenue')
            ->first();

        $cashierBreakdown = $baseQuery()
            ->with('cashier')
            ->selectRaw('cashier_id, COUNT(*) as orders_count, SUM(total) as total')
            ->groupBy('cashier_id')
            ->get()
            ->map(fn ($row) => [
                'cashier_name' => $row->cashier?->full_name ?? '—',
                'orders' => (int) $row->orders_count,
                'amount' => (float) $row->total,
            ]);

        $itemsSold = Order::withoutBranch()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.branch_id', $branchId)
            ->where('orders.status', OrderStatus::Completed)
            ->whereDate('orders.created_at', $date)
            ->selectRaw('order_items.name, SUM(order_items.quantity) as quantity')
            ->groupBy('order_items.name')
            ->orderByDesc('quantity')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'quantity_sold' => (int) $row->quantity,
            ]);

        return response()->json([
            'date' => $date,
            'total_orders' => $totalOrders,
            'payment_breakdown' => $paymentBreakdown,
            'total_discounts' => (float) ($totals?->total_discounts ?? 0),
            'total_revenue' => (float) ($totals?->total_revenue ?? 0),
            'cashier_breakdown' => $cashierBreakdown,
            'items_sold' => $itemsSold,
        ]);
    }
}
