<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\CreditTransactionType;
use App\Enums\InventoryLogType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\CreditTransaction;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Student;
use App\Models\SystemConfiguration;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'in:cash,gcash,wallet,subscription'],
            'student_search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $date = $validated['date'] ?? now()->toDateString();

        $query = Order::with(['items', 'student', 'cashier'])
            ->whereDate('created_at', $date)
            ->orderByDesc('created_at');

        if (! empty($validated['payment_method'])) {
            $query->where('payment_method', $validated['payment_method']);
        }

        if (! empty($validated['student_search'])) {
            $search = $validated['student_search'];
            $query->whereHas('student', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('student_number', 'like', "%{$search}%");
            });
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

    public function void(Request $request, Order $order): JsonResponse
    {
        $this->authorize('void', $order);

        if ($order->status === OrderStatus::Voided) {
            return response()->json(['message' => 'Order is already voided.'], 422);
        }

        $validated = $request->validate([
            'void_reason' => ['required', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $order, $validated) {
            $order->update([
                'status' => OrderStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $request->user()->id,
                'void_reason' => $validated['void_reason'],
            ]);

            $student = $order->student_id
                ? Student::lockForUpdate()->findOrFail($order->student_id)
                : null;

            if ($order->payment_method === PaymentMethod::Wallet && $student) {
                // On checkout, when credit was used, only the wallet portion (total - credit_amount)
                // was actually deducted from the wallet. Refund only that portion.
                $walletRefundAmount = max(0, (float) $order->total - (float) $order->credit_amount);

                if ($walletRefundAmount > 0) {
                    $student->deposit((int) round($walletRefundAmount * 100), [
                        'source' => 'void_refund',
                        'order_id' => $order->id,
                        'receipt_number' => $order->receipt_number,
                        'voided_by' => $request->user()->id,
                    ]);
                }
            }

            if ($order->is_credit && $student) {
                CreditTransaction::create([
                    'student_id' => $student->id,
                    'order_id' => $order->id,
                    'type' => CreditTransactionType::Voided,
                    'amount' => $order->credit_amount,
                    'notes' => "Credit reversed for voided order {$order->receipt_number}.",
                    'performed_by' => $request->user()->id,
                    'created_at' => now(),
                ]);

                $student->update([
                    'credit_balance' => max(0, (float) $student->credit_balance - (float) $order->credit_amount),
                ]);
            }

            // Inventory restoration — re-stock items deducted during checkout
            $saleLogs = InventoryLog::where('order_id', $order->id)
                ->where('type', InventoryLogType::Sale->value)
                ->get();

            foreach ($saleLogs as $saleLog) {
                $invItem = InventoryItem::lockForUpdate()->find($saleLog->inventory_item_id);
                if ($invItem) {
                    $restoredQty = (float) $invItem->quantity + abs((float) $saleLog->quantity_change);
                    $invItem->update(['quantity' => $restoredQty]);

                    InventoryLog::create([
                        'branch_id' => $order->branch_id,
                        'inventory_item_id' => $invItem->id,
                        'order_id' => $order->id,
                        'adjusted_by' => $request->user()->id,
                        'type' => InventoryLogType::Restock,
                        'quantity_change' => abs((float) $saleLog->quantity_change),
                        'stock_after' => $restoredQty,
                        'item_name_snapshot' => $saleLog->item_name_snapshot,
                        'reason' => 'Void: Order #'.$order->receipt_number,
                    ]);
                }
            }

            if ($student) {
                $student->refresh();

                $previousTotalSpent = (float) $student->total_spent + (float) $order->total;
                $newTotalSpent = max(0, (float) $student->total_spent - (float) $order->total);

                $threshold = SystemConfiguration::getValue('loyalty_point_threshold', 1000);
                $previousPoints = floor($previousTotalSpent / $threshold);
                $newPoints = floor($newTotalSpent / $threshold);
                $pointsToRemove = max(0, (int) ($previousPoints - $newPoints));

                $student->update([
                    'total_spent' => $newTotalSpent,
                    'points' => max(0, $student->points - $pointsToRemove),
                ]);
            }
        });

        activity('pos')
            ->causedBy($request->user())
            ->performedOn($order)
            ->withProperties([
                'receipt_number' => $order->receipt_number,
                'amount' => $order->total,
                'void_reason' => $validated['void_reason'],
                'voided_by' => $request->user()->id,
                'payment_method' => $order->payment_method?->value,
            ])
            ->log('pos.order_voided');

        $order->load(['items', 'student', 'cashier']);

        return response()->json([
            'message' => 'Order voided successfully.',
            'order' => new OrderResource($order),
        ]);
    }
}
