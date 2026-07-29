<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Concerns\ResolvesStudentLedger;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\LedgerEntryFormatter;
use App\Services\StudentLedgerQuery;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The parent-facing unified ledger.
 *
 * A separate class from the Kitchen controller because structure.md forbids Kitchen and
 * Portal controllers importing each other. The shared validation + pagination step lives
 * in ResolvesStudentLedger; formatting shared behaviour lives in LedgerEntryFormatter.
 */
class StudentLedgerController extends Controller
{
    use AuthorizesRequests;
    use ResolvesStudentLedger;

    public function __construct(
        private StudentLedgerQuery $ledgerQuery,
        private LedgerEntryFormatter $formatter,
    ) {}

    public function index(Request $request, Student $student): JsonResponse
    {
        $this->authorize('view', $student);

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
