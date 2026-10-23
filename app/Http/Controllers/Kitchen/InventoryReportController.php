<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\InventoryLogType;
use App\Exports\InventoryReportExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ExportInventoryReportRequest;
use App\Http\Requests\Kitchen\ShowInventoryReportRequest;
use App\Http\Resources\InventoryLogResource;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryReportController extends Controller
{
    public function index(ShowInventoryReportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $branchId = app('active_branch')->id;

        $items = InventoryItem::active()
            ->where('branch_id', $branchId)
            ->orderBy('name')
            ->get();

        $outOfStock = $items->filter(fn ($item) => $item->quantity == 0)->count();
        $belowThreshold = $items->filter(fn ($item) => $item->quantity > 0 && $item->quantity <= $item->restock_threshold)->count();
        $overStock = $items->filter(fn ($item) => $item->overstock_threshold !== null && (float) $item->quantity > (float) $item->overstock_threshold)->count();

        $logQuery = InventoryLog::forActiveItemsInBranch($branchId)
            ->with('adjustedBy:id,first_name,last_name')
            ->latest('inventory_logs.created_at');

        if (! empty($validated['from'])) {
            $logQuery->whereDate('inventory_logs.created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $logQuery->whereDate('inventory_logs.created_at', '<=', $validated['to']);
        }

        if (! empty($validated['type'])) {
            $logQuery->where('inventory_logs.type', $validated['type']);
        }

        if (! empty($validated['item_id'])) {
            $logQuery->where('inventory_logs.inventory_item_id', $validated['item_id']);
        }

        $paginatedLogs = $logQuery->paginate(25);

        $discrepancy = $this->buildDiscrepancySection($branchId, $validated);

        return response()->json([
            'summary' => [
                'out_of_stock' => $outOfStock,
                'below_threshold' => $belowThreshold,
                'over_stock' => $overStock,
                'total_items' => $items->count(),
            ],
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity' => (float) $item->quantity,
                'restock_threshold' => (float) $item->restock_threshold,
                'overstock_threshold' => $item->overstock_threshold !== null ? (float) $item->overstock_threshold : null,
                'cost_per_unit' => $item->cost_per_unit !== null ? (float) $item->cost_per_unit : null,
                'status' => $item->status,
                'updated_at' => $item->updated_at?->toDateTimeString(),
            ]),
            'logs' => [
                'data' => InventoryLogResource::collection($paginatedLogs->items()),
                'meta' => [
                    'current_page' => $paginatedLogs->currentPage(),
                    'last_page' => $paginatedLogs->lastPage(),
                    'per_page' => $paginatedLogs->perPage(),
                    'total' => $paginatedLogs->total(),
                ],
            ],
            'discrepancy' => $discrepancy,
        ]);
    }

    public function export(ExportInventoryReportRequest $request): BinaryFileResponse
    {
        $validated = $request->validated();

        $branch = app('active_branch');
        $branchId = $branch->id;

        $items = InventoryItem::active()
            ->where('branch_id', $branchId)
            ->orderBy('name')
            ->get();

        $logQuery = InventoryLog::forActiveItemsInBranch($branchId)
            ->with('adjustedBy:id,first_name,last_name')
            ->latest('inventory_logs.created_at');

        if (! empty($validated['from'])) {
            $logQuery->whereDate('inventory_logs.created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $logQuery->whereDate('inventory_logs.created_at', '<=', $validated['to']);
        }

        $logs = $logQuery->get();

        $from = $validated['from'] ?? now()->toDateString();
        $to = $validated['to'] ?? now()->toDateString();
        $filename = "inventory-report-{$branch->slug}-{$from}-{$to}.xlsx";

        return Excel::download(new InventoryReportExport($items, $logs), $filename);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildDiscrepancySection(int $branchId, array $filters): array
    {
        $query = InventoryLog::query()
            ->join('inventory_items', 'inventory_logs.inventory_item_id', '=', 'inventory_items.id')
            ->where('inventory_items.branch_id', $branchId)
            ->where('inventory_logs.type', InventoryLogType::Manual->value)
            ->select(
                'inventory_logs.inventory_item_id',
                'inventory_logs.item_name_snapshot',
                DB::raw('COUNT(*) as adjustment_count'),
                DB::raw('SUM(inventory_logs.quantity_change) as net_change'),
                DB::raw('MAX(inventory_logs.created_at) as last_adjusted_at')
            )
            ->groupBy('inventory_logs.inventory_item_id', 'inventory_logs.item_name_snapshot');

        if (! empty($filters['from'])) {
            $query->whereDate('inventory_logs.created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('inventory_logs.created_at', '<=', $filters['to']);
        }

        return $query->get()
            ->sortByDesc(fn ($row) => abs((float) $row->net_change))
            ->values()
            ->map(fn ($row) => [
                'inventory_item_id' => $row->inventory_item_id,
                'item_name' => $row->item_name_snapshot,
                'adjustment_count' => (int) $row->adjustment_count,
                'net_change' => (float) $row->net_change,
                'last_adjusted_at' => $row->last_adjusted_at,
            ])
            ->all();
    }
}
