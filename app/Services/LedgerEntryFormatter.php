<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Formats raw unified-ledger rows into the API contract shared by both frontends.
 *
 * Deliberately not a JsonResource: the rows are stdClass from a union subquery rather
 * than Eloquent models, and formatting needs a batched performer-name map that
 * JsonResource::collection() cannot pass through. Follows the inline-mapping precedent
 * in CreditReportController and WalletHistoryController.
 */
class LedgerEntryFormatter
{
    /**
     * @param  Collection<int, object>  $rows
     * @param  bool  $includeStaffOnlyNotes  Pass false for parent-facing responses. Waive
     *                                       reasons record personal circumstances such as
     *                                       family hardship or a disputed charge, so the
     *                                       reason text stays staff-only while the waive
     *                                       itself remains visible to explain the balance.
     * @return Collection<int, array<string, mixed>>
     */
    public function format(Collection $rows, bool $includeStaffOnlyNotes = true): Collection
    {
        $names = $this->resolvePerformerNames($rows);

        return $rows->map(function (object $row) use ($names, $includeStaffOnlyNotes): array {
            $type = LedgerEntryType::from($row->entry_type);
            $performerId = $this->performerId($row);

            $note = $this->resolveNote($row, $type);

            if (! $includeStaffOnlyNotes && $type === LedgerEntryType::CreditWaived) {
                $note = null;
            }

            return [
                'id' => $row->row_id,
                'date' => Carbon::parse($row->created_at)->toIso8601String(),
                'entry_type' => $type->value,
                'entry_label' => $type->label(),
                'direction' => $type->direction(),
                'amount' => round((float) $row->amount, 2),
                'payment_method' => $row->payment_method,
                'reference_number' => $row->reference_number,
                'note' => $note,
                'performed_by' => $performerId === null ? null : ($names[$performerId] ?? null),
                'voided' => (bool) ($row->voided ?? false),
                'wallet_transaction_id' => $row->wallet_transaction_id !== null
                    ? (int) $row->wallet_transaction_id
                    : null,
            ];
        })->values();
    }

    /**
     * Credit rows carry the performer in a column; wallet rows carry it inside `meta`,
     * under either `performed_by` (top-up) or `cashier_id` (inline reload).
     */
    private function performerId(object $row): ?int
    {
        if ($row->performed_by !== null) {
            return (int) $row->performed_by;
        }

        $meta = $this->decodeMeta($row);

        $id = $meta['performed_by'] ?? $meta['cashier_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    private function resolveNote(object $row, LedgerEntryType $type): ?string
    {
        if ($type->isCreditEntry()) {
            return $row->details === null ? null : (string) $row->details;
        }

        $meta = $this->decodeMeta($row);

        foreach (['note', 'source', 'payment_method'] as $key) {
            if (! empty($meta[$key])) {
                return (string) $meta[$key];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMeta(object $row): array
    {
        if (! isset($row->details) || $row->details === null) {
            return [];
        }

        if (is_array($row->details)) {
            return $row->details;
        }

        $decoded = json_decode((string) $row->details, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * One query for every performer across the whole page, never one per row.
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, string>
     */
    private function resolvePerformerNames(Collection $rows): array
    {
        $ids = $rows
            ->map(fn (object $row) => $this->performerId($row))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::withTrashed()
            ->whereIn('id', $ids)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (User $user) => [$user->id => trim("{$user->first_name} {$user->last_name}")])
            ->all();
    }
}
