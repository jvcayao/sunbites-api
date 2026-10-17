<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\StudentLedgerRequest;
use App\Models\Student;
use App\Services\StudentLedgerQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Shared query and pagination for the unified student ledger endpoint, used by both
 * the Kitchen and Portal StudentLedgerController. Validation lives in StudentLedgerRequest.
 *
 * Lives outside both namespaces because structure.md forbids Kitchen and Portal
 * controllers importing each other. Each controller still owns its own authorization and
 * response shape — only the identical query + paginate step is shared here.
 */
trait ResolvesStudentLedger
{
    private function paginatedLedgerEntries(StudentLedgerRequest $request, Student $student, StudentLedgerQuery $ledgerQuery): LengthAwarePaginator
    {
        $validated = $request->validated();

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
