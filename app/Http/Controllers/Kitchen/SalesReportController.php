<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\OrderStatus;
use App\Exports\SalesReportExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ExportSalesReportRequest;
use App\Http\Requests\Kitchen\ListSalesReportRequest;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesReportController extends Controller
{
    public function index(ListSalesReportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $branchId = app('active_branch')->id;
        $dateFrom = $validated['date_from'] ?? now()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();
        $perPage = $validated['per_page'] ?? 25;

        $query = $this->filteredOrders($branchId, $validated, $dateFrom, $dateTo)
            ->with(['items', 'student', 'cashier'])
            ->latest();

        $summary = $this->filteredOrders($branchId, $validated, $dateFrom, $dateTo)
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
            'data' => collect($orders->items())->map(fn (Order $o) => [
                ...$o->attributesToArray(),
                'items' => $o->items->toArray(),
                'student' => $o->student ? [
                    'id' => $o->student->id,
                    'first_name' => $o->student->first_name,
                    'last_name' => $o->student->last_name,
                    'full_name' => $o->student->full_name,
                    'student_number' => $o->student->student_number,
                    'grade_level' => $o->student->grade_level,
                    'section' => $o->student->section,
                ] : null,
                'cashier' => $o->cashier ? [
                    'id' => $o->cashier->id,
                    'first_name' => $o->cashier->first_name,
                    'last_name' => $o->cashier->last_name,
                    'full_name' => $o->cashier->full_name,
                ] : null,
            ]),
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

    public function export(ExportSalesReportRequest $request): BinaryFileResponse
    {
        $validated = $request->validated();

        $branch = app('active_branch');
        $dateFrom = $validated['date_from'] ?? now()->toDateString();
        $dateTo = $validated['date_to'] ?? now()->toDateString();

        $orders = $this->filteredOrders($branch->id, $validated, $dateFrom, $dateTo)
            ->with(['items', 'student', 'cashier'])
            ->latest()
            ->get();

        $filename = "sales-report-{$branch->slug}-{$dateFrom}-{$dateTo}.xlsx";

        return Excel::download(
            new SalesReportExport($orders, $branch->name, $dateFrom, $dateTo),
            $filename,
        );
    }

    /**
     * Completed orders of the branch in the date range, narrowed by the optional payment method,
     * walk-in and cashier filters. Callers add eager loads, ordering and aggregates.
     *
     * @param  array<string, mixed>  $validated
     * @return Builder<Order>
     */
    private function filteredOrders(int $branchId, array $validated, string $dateFrom, string $dateTo): Builder
    {
        return Order::withoutBranch()
            ->where('branch_id', $branchId)
            ->where('status', OrderStatus::Completed)
            ->whereBetween('created_at', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
            ->when(isset($validated['payment_method']), fn ($q) => $q->where('payment_method', $validated['payment_method']))
            ->when(isset($validated['is_walk_in']), fn ($q) => $validated['is_walk_in']
                ? $q->whereNull('student_id')
                : $q->whereNotNull('student_id'),
            )
            ->when(isset($validated['cashier_id']), fn ($q) => $q->where('cashier_id', $validated['cashier_id']));
    }
}
