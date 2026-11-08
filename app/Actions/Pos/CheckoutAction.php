<?php

namespace App\Actions\Pos;

use App\Enums\EnrollmentStatus;
use App\Enums\MenuCategory;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StudentType;
use App\Jobs\WalletAlertJob;
use App\Models\BranchSubscriptionConfig;
use App\Models\Order;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\SystemConfiguration;
use App\Models\User;
use App\Services\CreditLedgerService;
use App\Services\InventoryStockService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Create a POS order: payment, credit, stock deduction and loyalty points in one transaction.
 *
 * Runs in the active branch. The student and the menu items are looked up through
 * `HasBranch`, so only records in the active branch are found.
 */
class CheckoutAction
{
    public function __construct(
        private readonly CreditLedgerService $creditLedger,
        private readonly InventoryStockService $stock,
    ) {}

    /**
     * @param  array<string, mixed>  $input  CheckoutRequest::validated()
     */
    public function execute(array $input, User $cashier): Order
    {
        $paymentMethod = PaymentMethod::from($input['payment_method']);
        $isWalkIn = empty($input['student_id']);

        if ($paymentMethod === PaymentMethod::Wallet && $isWalkIn) {
            throw new HttpResponseException(response()->json(['message' => 'Walk-in customers cannot use the student wallet.'], 422));
        }

        if ($paymentMethod === PaymentMethod::Subscription && $isWalkIn) {
            throw new HttpResponseException(response()->json(['message' => 'Walk-in customers cannot use the subscription payment method.'], 422));
        }

        $menuItemIds = collect($input['items'])->pluck('pos_menu_item_id')->unique()->values();
        $menuItems = PosMenuItem::with('inventoryItems')->findMany($menuItemIds)->keyBy('id');

        if ($menuItems->count() !== $menuItemIds->count()) {
            throw new HttpResponseException(response()->json([
                'message' => 'One or more items are not available in this branch. Clear the cart and add them again.',
            ], 422));
        }

        // Pre-checkout: ensure every cart item has at least one inventory mapping
        foreach ($input['items'] as $cartItem) {
            /** @var PosMenuItem $menuItem */
            $menuItem = $menuItems[$cartItem['pos_menu_item_id']];
            if ($menuItem->inventoryItems->isEmpty()) {
                throw new HttpResponseException(response()->json([
                    'message' => 'One or more items are not configured for inventory tracking. Please contact your administrator.',
                ], 422));
            }
        }

        // Pre-checkout: aggregate deductions and verify sufficient stock
        $deductions = [];
        foreach ($input['items'] as $cartItem) {
            /** @var PosMenuItem $menuItem */
            $menuItem = $menuItems[$cartItem['pos_menu_item_id']];
            foreach ($menuItem->inventoryItems as $invItem) {
                $units = $invItem->pivot->quantity_used * $cartItem['quantity'];
                $deductions[$invItem->id] = ($deductions[$invItem->id] ?? 0) + $units;
            }
        }

        $loadedInventoryItems = $menuItems->flatMap->inventoryItems->keyBy('id');

        foreach ($deductions as $invItemId => $unitsToDeduct) {
            $invItem = $loadedInventoryItems[$invItemId];
            if ((float) $invItem->quantity - $unitsToDeduct < 0) {
                throw new HttpResponseException(response()->json(['message' => $invItem->name.' is out of stock.'], 422));
            }
        }

        $subtotal = collect($input['items'])->sum(fn ($item) => $menuItems[$item['pos_menu_item_id']]->price * $item['quantity']);

        $discountAmount = 0.0;
        if (! empty($input['discount_type']) && ! empty($input['discount_value'])) {
            $this->authorizeDiscount($cashier);
            $discountAmount = $input['discount_type'] === 'percent'
                ? round($subtotal * ($input['discount_value'] / 100), 2)
                : min((float) $input['discount_value'], $subtotal);
        }

        $total = max(0, $subtotal - $discountAmount);
        $notes = isset($input['notes']) ? strip_tags($input['notes']) : null;

        $order = DB::transaction(function () use ($cashier, $input, $paymentMethod, $total, $subtotal, $discountAmount, $menuItems, $deductions, $notes, $isWalkIn) {
            $student = null;
            $useCredit = ! empty($input['use_credit']);

            if (! $isWalkIn) {
                $student = Student::lockForUpdate()->findOrFail($input['student_id']);

                if ($student->enrollment_status !== EnrollmentStatus::Enrolled) {
                    abort(422, 'Student is not enrolled and cannot make purchases.');
                }

                if ($paymentMethod === PaymentMethod::Subscription && $student->student_type !== StudentType::Subscription) {
                    abort(422, 'This payment method is only available for subscription students.');
                }

                if ($paymentMethod === PaymentMethod::Subscription) {
                    $ineligible = $menuItems->filter(fn ($item) => $item->is_subscription_item !== true);
                    if ($ineligible->isNotEmpty()) {
                        abort(422, 'Item "'.$ineligible->first()->name.'" is not eligible for subscription payment.');
                    }

                    $todayUsed = $student->todaySubscriptionUsageByCategory();

                    $config = BranchSubscriptionConfig::forBranch(app('active_branch')->id);

                    $requestedByCategory = collect($input['items'])
                        ->groupBy(fn ($item) => $menuItems[$item['pos_menu_item_id']]->category->value)
                        ->map(fn ($items) => collect($items)->sum('quantity'));

                    foreach ($requestedByCategory as $category => $requestedQty) {
                        $used = $todayUsed[$category] ?? 0;
                        $limit = $config->limitForCategory(MenuCategory::from($category));
                        if ($used + $requestedQty > $limit) {
                            abort(422, "Daily {$category} limit of {$limit} reached for this student.");
                        }
                    }
                }

                if ($paymentMethod === PaymentMethod::Wallet) {
                    $walletBalance = $student->wallet?->balanceFloatNum ?? 0.0;
                    $shortfall = $total - $walletBalance;

                    if ($shortfall > 0 && ! $useCredit) {
                        abort(422, 'Insufficient wallet balance.');
                    }

                    if ($shortfall > 0 && $useCredit) {
                        $creditLimit = SystemConfiguration::getValue('credit_limit', 300);
                        if ($student->credit_balance + $shortfall > $creditLimit) {
                            abort(422, 'Credit limit exceeded.');
                        }
                    }
                }
            }

            // Lock the stock rows before anything is written, and re-check them under the lock (B-03).
            $lockedItems = $this->stock->lockForSale($deductions);

            $receiptNumber = Order::generateReceiptNumber(app('active_branch')->id);
            $creditAmount = 0.0;
            $isCredit = false;

            if ($paymentMethod === PaymentMethod::Wallet && $student) {
                $walletBalance = $student->wallet?->balanceFloatNum ?? 0.0;
                $shortfall = $total - $walletBalance;

                if ($shortfall > 0 && $useCredit) {
                    $isCredit = true;
                    $creditAmount = round($shortfall, 2);

                    $this->creditLedger->charge($student, $creditAmount, "order {$receiptNumber}", $cashier);
                    $student->refresh();

                    $availableBalance = $student->wallet?->balanceFloat ?? 0;
                    if ($availableBalance > 0) {
                        $student->withdraw((int) round($availableBalance * 100));
                    }
                } else {
                    $student->withdraw((int) round($total * 100));
                }
            }

            $amountTendered = $input['payment_method'] === 'cash' ? ($input['amount_tendered'] ?? 0) : null;
            $changeAmount = $amountTendered !== null ? max(0, $amountTendered - $total) : null;

            $order = Order::create([
                'branch_id' => app('active_branch')->id,
                'student_id' => $student?->id,
                'cashier_id' => $cashier->id,
                'receipt_number' => $receiptNumber,
                'payment_method' => $input['payment_method'],
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'discount_reason' => $input['discount_reason'] ?? null,
                'total' => $total,
                'amount_tendered' => $amountTendered,
                'change_amount' => $changeAmount,
                'reference_number' => $input['reference_number'] ?? null,
                'notes' => $notes,
                'is_credit' => $isCredit,
                'credit_amount' => $creditAmount,
                'status' => OrderStatus::Completed,
            ]);

            foreach ($input['items'] as $item) {
                $menuItem = $menuItems[$item['pos_menu_item_id']];
                $order->items()->create([
                    'pos_menu_item_id' => $menuItem->id,
                    'name' => $menuItem->name,
                    'price' => $menuItem->price,
                    'quantity' => $item['quantity'],
                    'line_total' => $menuItem->price * $item['quantity'],
                ]);
            }

            // Inventory deduction — runs inside the same DB transaction
            $this->stock->deductForSale($order, $menuItems, $input['items'], $cashier, app('active_branch')->id, $lockedItems);

            if ($student) {
                $previousTotalSpent = (float) $student->total_spent;
                $newTotalSpent = $previousTotalSpent + $total;

                $threshold = SystemConfiguration::getValue('loyalty_point_threshold', 1000);
                $previousPoints = floor($previousTotalSpent / $threshold);
                $newPoints = floor($newTotalSpent / $threshold);
                $pointsEarned = max(0, (int) ($newPoints - $previousPoints));

                $student->update([
                    'total_spent' => $newTotalSpent,
                    'points' => $student->points + $pointsEarned,
                ]);

                $order->update(['points_earned' => $pointsEarned]);
            }

            return $order;
        });

        $order->load(['items', 'student.wallet', 'cashier']);

        if ($order->payment_method === PaymentMethod::Wallet && $order->student_id) {
            $currentBalance = $order->student->wallet?->balanceFloatNum ?? 0.0;
            WalletAlertJob::dispatch($order->student_id, $currentBalance);
        }

        activity('pos')
            ->causedBy($cashier)
            ->performedOn($order)
            ->withProperties([
                'receipt_number' => $order->receipt_number,
                'total' => $order->total,
                'payment_method' => $order->payment_method?->value,
                'student_id' => $order->student_id ?? 'walk-in',
                'cashier_id' => $cashier->id,
                'is_credit' => $order->is_credit,
            ])
            ->log('pos.order_created');

        if ($discountAmount > 0) {
            activity('pos')
                ->causedBy($cashier)
                ->performedOn($order)
                ->withProperties([
                    'discount_type' => $input['discount_type'],
                    'amount' => $discountAmount,
                    'reason' => $input['discount_reason'] ?? null,
                    'receipt_number' => $order->receipt_number,
                ])
                ->log('pos.discount_applied');
        }

        return $order;
    }

    private function authorizeDiscount(User $cashier): void
    {
        if (! $cashier->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'Only admin and manager roles can apply discounts.');
        }
    }
}
