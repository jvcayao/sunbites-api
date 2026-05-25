<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\OrderStatus;
use App\Exports\SalesReportExport;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string'],
            'is_walk_in' => ['nullable', 'boolean'],
            'cashier_id' => ['nullable', 'integer', 'exists:users,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branchId = app('active_branch')->id;
        $dateFrom = $validated['date_from'] ?? now()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();
        $perPage = $validated['per_page'] ?? 25;

        $query = Order::withoutBranch()
            ->with(['items', 'student', 'cashier'])
            ->where('branch_id', $branchId)
            ->where('status', OrderStatus::Completed)
            ->whereBetween('created_at', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
            ->when(isset($validated['payment_method']), fn ($q) => $q->where('payment_method', $validated['payment_method']))
            ->when(isset($validated['is_walk_in']), fn ($q) => $validated['is_walk_in']
                ? $q->whereNull('student_id')
                : $q->whereNotNull('student_id'),
            )
            ->when(isset($validated['cashier_id']), fn ($q) => $q->where('cashier_id', $validated['cashier_id']))
            ->latest();

        $summary = Order::withoutBranch()
            ->where('branch_id', $branchId)
            ->where('status', OrderStatus::Completed)
            ->whereBetween('created_at', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
            ->when(isset($validated['payment_method']), fn ($q) => $q->where('payment_method', $validated['payment_method']))
            ->when(isset($validated['is_walk_in']), fn ($q) => $validated['is_walk_in']
                ? $q->whereNull('student_id')
                : $q->whereNotNull('student_id'),
            )
            ->when(isset($validated['cashier_id']), fn ($q) => $q->where('cashier_id', $validated['cashier_id']))
            ->selectRaw('
                SUM(total) as total_revenue,
                COUNT(*) as total_orders,
                AVG(total) as avg_order_value,
                SUM(discount_amount) as total_discounts
            ')
            ->first();

        $totalRevenue = (float) ($summary?->total_revenue ?? 0);
        $totalDiscounts = (float) ($summary?->total_discounts ?? 0);

        $orders = $query->paginate($perPage);

        return response()->json([
            'data' => $orders->items(),
            'meta' => $this->paginationMeta($orders),
            'summary' => [
                'total_revenue' => $totalRevenue,
                'total_orders' => (int) ($summary?->total_orders ?? 0),
                'avg_order_value' => round((float) ($summary?->avg_order_value ?? 0), 2),
                'total_discounts' => $totalDiscounts,
                'net_revenue' => round($totalRevenue - $totalDiscounts, 2),
            ],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string'],
            'is_walk_in' => ['nullable', 'boolean'],
            'cashier_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $branch = app('active_branch');
        $dateFrom = $validated['date_from'] ?? now()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();

        $orders = Order::withoutBranch()
            ->with(['items', 'student', 'cashier'])
            ->where('branch_id', $branch->id)
            ->where('status', OrderStatus::Completed)
            ->whereBetween('created_at', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
            ->when(isset($validated['payment_method']), fn ($q) => $q->where('payment_method', $validated['payment_method']))
            ->when(isset($validated['is_walk_in']), fn ($q) => $validated['is_walk_in']
                ? $q->whereNull('student_id')
                : $q->whereNotNull('student_id'),
            )
            ->when(isset($validated['cashier_id']), fn ($q) => $q->where('cashier_id', $validated['cashier_id']))
            ->latest()
            ->get();

        $filename = "sales-report-{$branch->slug}-{$dateFrom}-{$dateTo}.xlsx";

        return Excel::download(
            new SalesReportExport($orders, $branch->name, $dateFrom, $dateTo),
            $filename,
        );
    }
}
