<?php

namespace App\Http\Controllers\Kitchen;

use App\Exports\BillingReportExport;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BillingReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'school_month' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:paid,unpaid'],
            'grade_level' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branchId = app('active_branch')->id;
        $year = $validated['year'] ?? now()->year;
        $perPage = $validated['per_page'] ?? 50;

        $studentIds = Student::where('branch_id', $branchId)->pluck('id');

        $query = StudentMonthlyPayment::with(['student', 'recorder'])
            ->whereIn('student_id', $studentIds)
            ->where('year', $year)
            ->when(isset($validated['school_month']), fn ($q) => $q->where('school_month', $validated['school_month']))
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->when(isset($validated['grade_level']), fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('grade_level', $validated['grade_level'])))
            ->orderByRaw("CASE WHEN status = 'unpaid' THEN 0 ELSE 1 END")
            ->orderBy(
                Student::select('last_name')->whereColumn('students.id', 'student_monthly_payments.student_id')->limit(1),
            );

        $summaryBase = StudentMonthlyPayment::whereIn('student_id', $studentIds)->where('year', $year)
            ->when(isset($validated['school_month']), fn ($q) => $q->where('school_month', $validated['school_month']))
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->when(isset($validated['grade_level']), fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('grade_level', $validated['grade_level'])));

        $totalSubscribers = (clone $summaryBase)->distinct('student_id')->count('student_id');
        $totalCollected = (float) (clone $summaryBase)->where('status', 'paid')->sum('amount');
        $totalOutstanding = (float) (clone $summaryBase)->where('status', 'unpaid')->sum('amount');
        $totalAmount = $totalCollected + $totalOutstanding;
        $collectionRate = $totalAmount > 0 ? round(($totalCollected / $totalAmount) * 100, 2) : 0;

        $payments = $query->paginate($perPage);

        return response()->json([
            'data' => $payments->items(),
            'meta' => $this->paginationMeta($payments),
            'summary' => [
                'total_subscribers' => $totalSubscribers,
                'total_collected' => $totalCollected,
                'total_outstanding' => $totalOutstanding,
                'collection_rate' => $collectionRate,
            ],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'school_month' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:paid,unpaid'],
            'grade_level' => ['nullable', 'string'],
        ]);

        $branch = app('active_branch');
        $branchId = $branch->id;
        $year = $validated['year'] ?? now()->year;

        $studentIds = Student::where('branch_id', $branchId)->pluck('id');

        $payments = StudentMonthlyPayment::with(['student', 'recorder'])
            ->whereIn('student_id', $studentIds)
            ->where('year', $year)
            ->when(isset($validated['school_month']), fn ($q) => $q->where('school_month', $validated['school_month']))
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->when(isset($validated['grade_level']), fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('grade_level', $validated['grade_level'])))
            ->orderByRaw("CASE WHEN status = 'unpaid' THEN 0 ELSE 1 END")
            ->get();

        $totalCollected = (float) $payments->where('status', 'paid')->sum('amount');
        $totalOutstanding = (float) $payments->where('status', 'unpaid')->sum('amount');
        $totalAmount = $totalCollected + $totalOutstanding;
        $collectionRate = $totalAmount > 0 ? round(($totalCollected / $totalAmount) * 100, 2) : 0;

        $summary = [
            'total_subscribers' => $payments->unique('student_id')->count(),
            'total_collected' => $totalCollected,
            'total_outstanding' => $totalOutstanding,
            'collection_rate' => $collectionRate,
        ];

        $filename = "billing-report-{$branch->slug}-{$year}.xlsx";

        return Excel::download(new BillingReportExport($payments, $summary), $filename);
    }
}
