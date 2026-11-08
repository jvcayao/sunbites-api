<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Concerns\ResolvesStudentLedger;
use App\Http\Controllers\Controller;
use App\Http\Requests\StudentLedgerRequest;
use App\Models\Student;
use App\Services\LedgerEntryFormatter;
use App\Services\StudentLedgerQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Authorize;

/**
 * The parent-facing unified ledger.
 *
 * A separate class from the Kitchen controller because structure.md forbids Kitchen and
 * Portal controllers importing each other. Validation lives in StudentLedgerRequest, the
 * shared query + pagination step in ResolvesStudentLedger, and formatting in LedgerEntryFormatter.
 */
class StudentLedgerController extends Controller
{
    use ResolvesStudentLedger;

    public function __construct(
        private readonly StudentLedgerQuery $ledgerQuery,
        private readonly LedgerEntryFormatter $formatter,
    ) {}

    #[Authorize('view', 'student')]
    public function index(StudentLedgerRequest $request, Student $student): JsonResponse
    {
        $entries = $this->paginatedLedgerEntries($request, $student, $this->ledgerQuery);

        return response()->json([
            'student' => [
                'id' => $student->id,
                'full_name' => $student->full_name,
            ],
            'balance' => $student->wallet?->balanceFloatNum ?? 0.0,
            'credit_balance' => (float) $student->credit_balance,
            'data' => $this->formatter->format(collect($entries->items()), includeStaffOnlyNotes: false),
            'meta' => $this->paginationMeta($entries),
        ]);
    }
}
