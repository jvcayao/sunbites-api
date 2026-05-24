<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\CreditTransactionType;
use App\Enums\EnrollmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Jobs\WalletAlertJob;
use App\Models\CreditTransaction;
use App\Models\Order;
use App\Models\PosMenuItem;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['nullable', 'exists:students,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.pos_menu_item_id' => ['required', 'exists:pos_menu_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'alpha_num', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'discount_type' => ['nullable', 'in:percent,fixed'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'use_credit' => ['boolean'],
        ]);

        $paymentMethod = PaymentMethod::from($validated['payment_method']);
        $isWalkIn = empty($validated['student_id']);

        if ($paymentMethod === PaymentMethod::Wallet && $isWalkIn) {
            return response()->json(['message' => 'Walk-in customers cannot use the student wallet.'], 422);
        }

        if ($paymentMethod === PaymentMethod::Subscription && $isWalkIn) {
            return response()->json(['message' => 'Walk-in customers cannot use the subscription payment method.'], 422);
        }

        $menuItemIds = collect($validated['items'])->pluck('pos_menu_item_id')->unique()->values();
        $menuItems = PosMenuItem::findMany($menuItemIds)->keyBy('id');

        $subtotal = collect($validated['items'])->sum(fn ($item) => $menuItems[$item['pos_menu_item_id']]->price * $item['quantity']);

        $discountAmount = 0.0;
        if (! empty($validated['discount_type']) && ! empty($validated['discount_value'])) {
            $this->authorizeDiscount($request);
            $discountAmount = $validated['discount_type'] === 'percent'
                ? round($subtotal * ($validated['discount_value'] / 100), 2)
                : min((float) $validated['discount_value'], $subtotal);
        }

        $total = max(0, $subtotal - $discountAmount);
        $notes = isset($validated['notes']) ? strip_tags($validated['notes']) : null;

        $order = DB::transaction(function () use ($request, $validated, $paymentMethod, $total, $subtotal, $discountAmount, $menuItems, $notes, $isWalkIn) {
            $student = null;
            $useCredit = ! empty($validated['use_credit']);

            if (! $isWalkIn) {
                $student = Student::lockForUpdate()->findOrFail($validated['student_id']);

                if ($student->enrollment_status !== EnrollmentStatus::Enrolled) {
                    abort(422, 'Student is not enrolled and cannot make purchases.');
                }

                if ($paymentMethod === PaymentMethod::Subscription && $student->student_type !== StudentType::Subscription) {
                    abort(422, 'This payment method is only available for subscription students.');
                }

                if ($paymentMethod === PaymentMethod::Wallet) {
                    $walletBalance = $student->wallet?->balanceFloat ?? 0;
                    $shortfall = $total - $walletBalance;

                    if ($shortfall > 0 && ! $useCredit) {
                        abort(422, 'Insufficient wallet balance.');
                    }

                    if ($shortfall > 0 && $useCredit) {
                        $creditLimit = config('sunbites.credit_limit', 300);
                        if ($student->credit_balance + $shortfall > $creditLimit) {
                            abort(422, 'Credit limit exceeded.');
                        }
                    }
                }
            }

            $receiptNumber = Order::generateReceiptNumber(app('active_branch')->id);
            $creditAmount = 0.0;
            $isCredit = false;

            if ($paymentMethod === PaymentMethod::Wallet && $student) {
                $walletBalance = $student->wallet?->balanceFloat ?? 0;
                $shortfall = $total - $walletBalance;

                if ($shortfall > 0 && $useCredit) {
                    $isCredit = true;
                    $creditAmount = round($shortfall, 2);

                    CreditTransaction::create([
                        'student_id' => $student->id,
                        'type' => CreditTransactionType::Charged,
                        'amount' => $creditAmount,
                        'notes' => "Credit used for order {$receiptNumber}.",
                        'performed_by' => $request->user()->id,
                        'created_at' => now(),
                    ]);

                    $student->increment('credit_balance', $creditAmount);
                    $student->refresh();

                    $availableBalance = $student->wallet?->balanceFloat ?? 0;
                    if ($availableBalance > 0) {
                        $student->withdraw((int) round($availableBalance * 100));
                    }
                } else {
                    $student->withdraw((int) round($total * 100));
                }
            }

            $amountTendered = $validated['payment_method'] === 'cash' ? ($validated['amount_tendered'] ?? 0) : null;
            $changeAmount = $amountTendered !== null ? max(0, $amountTendered - $total) : null;

            $order = Order::create([
                'branch_id' => app('active_branch')->id,
                'student_id' => $student?->id,
                'cashier_id' => $request->user()->id,
                'receipt_number' => $receiptNumber,
                'payment_method' => $validated['payment_method'],
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'discount_reason' => $validated['discount_reason'] ?? null,
                'total' => $total,
                'amount_tendered' => $amountTendered,
                'change_amount' => $changeAmount,
                'reference_number' => $validated['reference_number'] ?? null,
                'notes' => $notes,
                'is_credit' => $isCredit,
                'credit_amount' => $creditAmount,
                'status' => OrderStatus::Completed,
            ]);

            foreach ($validated['items'] as $item) {
                $menuItem = $menuItems[$item['pos_menu_item_id']];
                $order->items()->create([
                    'pos_menu_item_id' => $menuItem->id,
                    'name' => $menuItem->name,
                    'price' => $menuItem->price,
                    'quantity' => $item['quantity'],
                    'line_total' => $menuItem->price * $item['quantity'],
                ]);
            }

            if ($student) {
                $previousTotalSpent = (float) $student->total_spent;
                $newTotalSpent = $previousTotalSpent + $total;

                $threshold = config('sunbites.loyalty_point_threshold', 1000);
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
            $currentBalance = $order->student->wallet?->balanceFloat ?? 0;
            WalletAlertJob::dispatch($order->student_id, $currentBalance);
        }

        activity('pos')
            ->causedBy($request->user())
            ->performedOn($order)
            ->withProperties([
                'receipt_number' => $order->receipt_number,
                'total' => $order->total,
                'payment_method' => $order->payment_method?->value,
                'student_id' => $order->student_id ?? 'walk-in',
                'cashier_id' => $request->user()->id,
                'is_credit' => $order->is_credit,
            ])
            ->log('pos.order_created');

        if ($discountAmount > 0) {
            activity('pos')
                ->causedBy($request->user())
                ->performedOn($order)
                ->withProperties([
                    'discount_type' => $validated['discount_type'],
                    'amount' => $discountAmount,
                    'reason' => $validated['discount_reason'] ?? null,
                    'receipt_number' => $order->receipt_number,
                ])
                ->log('pos.discount_applied');
        }

        return response()->json([
            'message' => 'Order created successfully.',
            'order' => new OrderResource($order),
        ], 201);
    }

    private function authorizeDiscount(Request $request): void
    {
        if (! $request->user()->hasAnyRole(['admin', 'manager'])) {
            abort(403, 'Only admin and manager roles can apply discounts.');
        }
    }
}
