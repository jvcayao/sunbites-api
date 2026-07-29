<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\CreditSettlementMethod;
use App\Enums\CreditTransactionType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\CreditTransaction;
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
            'credit_collections' => $this->creditCollections($branchId, $date),
            'items_sold' => $itemsSold,
        ]);
    }

    /**
     * Credit collected today, split by how it was paid.
     *
     * Kept out of `total_revenue` and `payment_breakdown` on purpose: the revenue was already
     * booked as a sale on the date of the original order. Counting a settlement as revenue
     * would double-count it. This is a receivable converting to cash, reported separately so
     * the drawer reconciles as Sales + Top-ups + Credit Collections.
     *
     * `from_wallet` brings in no physical money and must never be added to expected cash.
     *
     * @return array<string, mixed>
     */
    private function creditCollections(int $branchId, string $date): array
    {
        $byMethod = CreditTransaction::query()
            ->where('branch_id', $branchId)
            ->where('type', CreditTransactionType::Settled->value)
            ->whereDate('created_at', $date)
            ->selectRaw('payment_method, COUNT(*) AS count, SUM(amount) AS total')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');

        $collections = [
            'total' => 0.0,
            'count' => 0,
            'cash' => 0.0,
            'gcash' => 0.0,
            'bank_transfer' => 0.0,
            'from_wallet' => 0.0,
            'expected_in_drawer' => 0.0,
        ];

        foreach (CreditSettlementMethod::cases() as $method) {
            $row = $byMethod->get($method->value);
            $amount = round((float) ($row->total ?? 0), 2);

            $key = $method === CreditSettlementMethod::Wallet ? 'from_wallet' : $method->value;
            $collections[$key] = $amount;

            $collections['total'] = round($collections['total'] + $amount, 2);
            $collections['count'] += (int) ($row->count ?? 0);

            if ($method->bringsInCash()) {
                $collections['expected_in_drawer'] = round($collections['expected_in_drawer'] + $amount, 2);
            }
        }

        return $collections;
    }
}
