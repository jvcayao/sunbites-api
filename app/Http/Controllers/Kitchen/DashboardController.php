<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\OrderStatus;
use App\Enums\StaffStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\PosMenuItem;
use App\Models\StaffDailyStatus;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branch = app('active_branch');
        $branchId = $branch->id;
        $today = now()->toDateString();

        $totalStudents = Student::where('branch_id', $branchId)->count();
        $enrolledCount = Student::where('branch_id', $branchId)->where('enrollment_status', 'enrolled')->count();

        $completedOrdersToday = Order::withoutBranch()
            ->where('branch_id', $branchId)
            ->where('status', OrderStatus::Completed)
            ->whereDate('created_at', $today);

        $mealsToday = (clone $completedOrdersToday)->count();
        $revenueToday = (float) (clone $completedOrdersToday)->sum('total');
        $walkInOrders = (clone $completedOrdersToday)->whereNull('student_id')->count();
        $walletPaymentOrders = (clone $completedOrdersToday)->where('payment_method', 'wallet')->count();

        $recentOrders = Order::withoutBranch()
            ->with(['items', 'student', 'cashier'])
            ->where('branch_id', $branchId)
            ->where('status', OrderStatus::Completed)
            ->whereDate('created_at', $today)
            ->latest()
            ->take(10)
            ->get()
            ->map(fn ($order) => [
                'id' => $order->id,
                'receipt_number' => $order->receipt_number,
                'created_at' => $order->created_at->toDateTimeString(),
                'student' => $order->student?->full_name,
                'items' => $order->items->map(fn ($item) => [
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                ]),
                'payment_method' => $order->payment_method?->value,
                'total' => (float) $order->total,
            ]);

        $lowStock = InventoryItem::where('branch_id', $branchId)
            ->where(fn ($q) => $q->where('quantity', 0)->orWhereColumn('quantity', '<=', 'restock_threshold'))
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity' => (float) $item->quantity,
                'restock_threshold' => (float) $item->restock_threshold,
                'status' => $item->quantity == 0 ? 'out' : 'low',
            ]);

        $creditAlerts = Student::where('branch_id', $branchId)
            ->where('credit_balance', '>', 0)
            ->get()
            ->map(fn ($student) => [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'grade_level' => $student->grade_level,
                'credit_balance' => (float) $student->credit_balance,
            ]);

        $topItems = PosMenuItem::withoutBranch()
            ->selectRaw('pos_menu_items.id, pos_menu_items.name, SUM(order_items.quantity) as total_qty')
            ->join('order_items', 'order_items.pos_menu_item_id', '=', 'pos_menu_items.id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.branch_id', $branchId)
            ->where('orders.status', OrderStatus::Completed)
            ->whereDate('orders.created_at', $today)
            ->groupBy('pos_menu_items.id', 'pos_menu_items.name')
            ->orderByDesc('total_qty')
            ->limit(5)
            ->get()
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity_sold' => (int) $item->total_qty,
            ]);

        $staffRoster = $branch->users()
            ->with(['staffDailyStatuses' => fn ($q) => $q->whereDate('date', $today)])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'roles' => $user->getRoleNames()->values()->all(),
                'status' => $user->staffDailyStatuses->first()?->status?->value ?? StaffStatus::Working->value,
            ]);

        return response()->json([
            'total_students' => $totalStudents,
            'enrolled_count' => $enrolledCount,
            'meals_today' => $mealsToday,
            'revenue_today' => $revenueToday,
            'walk_in_orders' => $walkInOrders,
            'wallet_payment_orders' => $walletPaymentOrders,
            'recent_orders' => $recentOrders,
            'low_stock' => $lowStock,
            'credit_alerts' => $creditAlerts,
            'top_items' => $topItems,
            'staff_roster' => $staffRoster,
        ]);
    }

    public function updateStaffStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'status' => ['required', Rule::enum(StaffStatus::class)],
        ]);

        $branch = app('active_branch');

        $record = StaffDailyStatus::updateOrCreate(
            ['user_id' => $validated['user_id'], 'date' => today()],
            [
                'branch_id' => $branch->id,
                'status' => $validated['status'],
                'updated_by' => $request->user()->id,
            ],
        );

        return response()->json($record);
    }
}
