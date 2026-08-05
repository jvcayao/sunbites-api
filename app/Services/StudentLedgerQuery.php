<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Models\Student;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Builds the unified student ledger: wallet transactions and credit ledger entries
 * merged into one chronological stream.
 *
 * Returns a query builder rather than results so the caller controls pagination, and so
 * the Kitchen and Portal controllers share one definition without importing each other.
 *
 * The merge happens in SQL via UNION ALL rather than in PHP because both apps expose an
 * "All time" filter — an in-memory merge would be unbounded by design.
 */
class StudentLedgerQuery
{
    /**
     * @param  'all'|'topup'|'purchase'|'credit'  $entryFilter
     */
    public function for(
        Student $student,
        string $entryFilter = 'all',
        ?string $from = null,
        ?string $to = null,
    ): Builder {
        $credit = $this->creditLeg($student);
        $walletId = $student->wallet?->id;

        $ledger = $walletId === null
            ? $credit
            : $this->walletLeg($walletId)->unionAll($credit);

        $types = array_map(fn (LedgerEntryType $type) => $type->value, LedgerEntryType::forFilter($entryFilter));

        return DB::query()
            ->fromSub($ledger, 'ledger')
            ->whereIn('entry_type', $types)
            ->when($from !== null, fn (Builder $q) => $q->whereDate('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('row_id');
    }

    /**
     * `ABS(amount) / 100.0` — bavix stores minor units and signs withdrawals negative.
     * The `.0` is mandatory: tests run on SQLite, which performs integer division, so
     * `2550 / 100` would silently yield 25 and destroy the centavos.
     *
     * Joins `wallet_topup_voids` twice: `original_void` identifies a deposit that has
     * since been voided (drives the `voided` flag), `refund_void` identifies the
     * withdrawal that a void itself created (drives the `topup_voided` entry_type so the
     * reversal doesn't display as an ordinary purchase).
     */
    private function walletLeg(int $walletId): Builder
    {
        return DB::table('transactions')
            ->leftJoin('wallet_topup_voids as original_void', 'original_void.wallet_transaction_id', '=', 'transactions.id')
            ->leftJoin('wallet_topup_voids as refund_void', 'refund_void.refund_wallet_transaction_id', '=', 'transactions.id')
            ->selectRaw("
                CONCAT('wallet-', transactions.id) AS row_id,
                transactions.created_at,
                CASE WHEN refund_void.id IS NOT NULL THEN 'topup_voided' ELSE transactions.type END AS entry_type,
                ABS(transactions.amount) / 100.0 AS amount,
                transactions.meta AS details,
                NULL AS payment_method,
                NULL AS reference_number,
                NULL AS order_id,
                NULL AS performed_by,
                CASE WHEN original_void.id IS NOT NULL THEN 1 ELSE 0 END AS voided,
                CASE WHEN transactions.type = 'deposit' THEN transactions.id ELSE NULL END AS wallet_transaction_id
            ")
            ->where('transactions.wallet_id', $walletId)
            ->where('transactions.confirmed', true)
            ->whereNull('transactions.deleted_at');
    }

    /**
     * Trailing `voided`/`wallet_transaction_id` placeholder columns must stay in lockstep
     * with walletLeg()'s — unionAll() is positional, so a column-count mismatch here fails
     * at the database level, not in a way a PHP-level diff review would catch.
     */
    private function creditLeg(Student $student): Builder
    {
        return DB::table('credit_transactions')
            ->selectRaw("
                CONCAT('credit-', id) AS row_id,
                created_at,
                CONCAT('credit_', type) AS entry_type,
                amount,
                notes AS details,
                payment_method,
                reference_number,
                order_id,
                performed_by,
                0 AS voided,
                NULL AS wallet_transaction_id
            ")
            ->where('student_id', $student->id);
    }
}
