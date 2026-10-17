<?php

namespace App\Actions\Pos;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Student;
use App\Models\SystemConfiguration;
use App\Models\User;
use App\Services\CreditLedgerService;
use App\Services\InventoryStockService;
use Illuminate\Support\Facades\DB;

/**
 * Void a completed order: wallet refund, credit reversal, restock and loyalty recompute in
 * one transaction.
 *
 * The order comes from route model binding, which is branch-scoped through `HasBranch`. The
 * caller has already checked the policy and that the order is not yet voided.
 */
class VoidOrderAction
{
    public function __construct(
        private readonly CreditLedgerService $creditLedger,
        private readonly InventoryStockService $stock,
    ) {}

    public function execute(Order $order, User $voidedBy, string $reason): Order
    {
        DB::transaction(function () use ($voidedBy, $order, $reason) {
            $order->update([
                'status' => OrderStatus::Voided,
                'voided_at' => now(),
                'voided_by' => $voidedBy->id,
                'void_reason' => $reason,
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
                        'voided_by' => $voidedBy->id,
                    ]);
                }
            }

            if ($order->is_credit && $student) {
                $this->creditLedger->void($student, $order, $voidedBy);
            }

            // Inventory restoration — re-stock items deducted during checkout
            $this->stock->restockForVoid($order, $voidedBy);

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
            ->causedBy($voidedBy)
            ->performedOn($order)
            ->withProperties([
                'receipt_number' => $order->receipt_number,
                'amount' => $order->total,
                'void_reason' => $reason,
                'voided_by' => $voidedBy->id,
                'payment_method' => $order->payment_method?->value,
            ])
            ->log('pos.order_voided');

        return $order->load(['items', 'student', 'cashier']);
    }
}
