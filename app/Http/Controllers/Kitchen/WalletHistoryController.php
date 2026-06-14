<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WalletHistoryController extends Controller
{
    public function index(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:purchases,topups'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $perPage = $validated['per_page'] ?? 15;
        $search = $validated['search'] ?? null;

        if ($validated['type'] === 'purchases') {
            return $this->purchases($student, $search, $perPage);
        }

        return $this->topups($student, $search, $perPage);
    }

    private function purchases(Student $student, ?string $search, int $perPage): JsonResponse
    {
        $orders = Order::where('student_id', $student->id)
            ->where('status', '!=', OrderStatus::Voided)
            ->when($search, fn ($q) => $q->whereHas(
                'items',
                fn ($iq) => $iq->where('name', 'like', "%{$search}%")
            ))
            ->with('items:id,order_id,name')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $data = $orders->getCollection()->map(fn ($order) => [
            'id' => $order->id,
            'date' => $order->created_at->toIso8601String(),
            'description' => $order->items->pluck('name')->filter()->join(', '),
            'amount' => (float) $order->total,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => $this->paginationMeta($orders),
        ]);
    }

    private function topups(Student $student, ?string $search, int $perPage): JsonResponse
    {
        $wallet = $student->wallet;

        if (! $wallet) {
            return response()->json([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => $perPage],
            ]);
        }

        $transactions = DB::table('transactions')
            ->where('wallet_id', $wallet->id)
            ->where('type', 'deposit')
            ->where('transactions.confirmed', true)
            ->whereNull('transactions.deleted_at');

        if ($search) {
            $matchingUserIds = User::where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->pluck('id')
                ->toArray();

            if (empty($matchingUserIds)) {
                return response()->json([
                    'data' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => $perPage],
                ]);
            }

            // Each ID appears in JSON meta as: {"performed_by": 123}
            $transactions->where(function ($q) use ($matchingUserIds) {
                foreach ($matchingUserIds as $userId) {
                    $q->orWhere('transactions.meta', 'like', "%\"performed_by\": {$userId}%");
                }
            });
        }

        $transactions = $transactions
            ->orderByDesc('created_at')
            ->paginate($perPage, ['id', 'amount', 'meta', 'created_at']);

        // Resolve performed_by user IDs from meta in a single query (avoid N+1)
        $performedByIds = $transactions->getCollection()
            ->map(fn ($tx) => data_get(json_decode($tx->meta, true), 'performed_by'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $usersById = User::whereIn('id', $performedByIds)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($user) => [$user->id => trim("{$user->first_name} {$user->last_name}")]);

        $data = $transactions->getCollection()->map(fn ($tx) => $this->formatTopup($tx, $usersById));

        return response()->json([
            'data' => $data,
            'meta' => $this->paginationMeta($transactions),
        ]);
    }

    /** @param Collection<int, string> $usersById */
    private function formatTopup(object $tx, Collection $usersById): array
    {
        $performedById = data_get(json_decode($tx->meta, true), 'performed_by');
        $addedByName = $performedById ? ($usersById[$performedById] ?? '—') : '—';

        return [
            'id' => $tx->id,
            'date' => Carbon::parse($tx->created_at)->toIso8601String(),
            'description' => 'Wallet Top-Up',
            'amount' => abs((float) $tx->amount) / 100,
            'added_by' => $addedByName,
        ];
    }
}
