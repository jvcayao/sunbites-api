<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Student;
use App\Services\StudentLedgerQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Shared validation and pagination for the unified student ledger endpoint, used by both
 * the Kitchen and Portal StudentLedgerController.
 *
 * Lives outside both namespaces because structure.md forbids Kitchen and Portal
 * controllers importing each other. Each controller still owns its own authorization and
 * response shape — only the identical validation + query + paginate step is shared here.
 */
trait ResolvesStudentLedger
{
    private function paginatedLedgerEntries(Request $request, Student $student, StudentLedgerQuery $ledgerQuery): LengthAwarePaginator
    {
        $validated = $request->validate([
            'entry_type' => ['nullable', 'in:all,topup,purchase,credit'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $student->loadMissing('wallet');

        return $ledgerQuery
            ->for(
                $student,
                $validated['entry_type'] ?? 'all',
                $validated['from'] ?? null,
                $validated['to'] ?? null,
            )
            ->paginate($validated['per_page'] ?? 20);
    }
}
