<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Concerns\ResolvesStudentLedger;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\LedgerEntryFormatter;
use App\Services\StudentLedgerQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentLedgerController extends Controller
{
    use ResolvesStudentLedger;

    public function __construct(
        private StudentLedgerQuery $ledgerQuery,
        private LedgerEntryFormatter $formatter,
    ) {}

    public function index(Request $request, Student $student): JsonResponse
    {
        $entries = $this->paginatedLedgerEntries($request, $student, $this->ledgerQuery);

        return response()->json([
            'data' => $this->formatter->format(collect($entries->items())),
            'meta' => $this->paginationMeta($entries),
        ]);
    }
}
