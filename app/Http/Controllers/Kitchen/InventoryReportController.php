<?php

namespace App\Http\Controllers\Kitchen;

use App\Exports\InventoryReportExport;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branchId = app('active_branch')->id;

        $items = InventoryItem::where('branch_id', $branchId)
            ->orderBy('name')
            ->get();

        $outOfStock = $items->filter(fn ($item) => $item->quantity == 0)->count();
        $belowThreshold = $items->filter(fn ($item) => $item->quantity > 0 && $item->quantity <= $item->restock_threshold)->count();

        return response()->json([
            'data' => $items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'unit' => $item->unit,
                'quantity' => (float) $item->quantity,
                'restock_threshold' => (float) $item->restock_threshold,
                'status' => $item->status,
                'updated_at' => $item->updated_at?->toDateTimeString(),
            ]),
            'summary' => [
                'out_of_stock' => $outOfStock,
                'below_threshold' => $belowThreshold,
                'total_items' => $items->count(),
            ],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $branch = app('active_branch');
        $branchId = $branch->id;

        $items = InventoryItem::where('branch_id', $branchId)
            ->orderBy('name')
            ->get();

        $filename = "inventory-report-{$branch->slug}-".now()->format('Y-m-d').'.xlsx';

        return Excel::download(new InventoryReportExport($items), $filename);
    }
}
