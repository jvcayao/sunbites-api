<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Exports\BillingReportExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ExportBillingReportRequest;
use App\Http\Requests\Kitchen\ListBillingReportRequest;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BillingReportController extends Controller
{
    public function index(ListBillingReportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['school_month'] ??= SchoolMonth::fromMonthNumber(now()->month)?->value;
        $validated['year'] ??= now()->month >= 6 ? now()->year : now()->year - 1;
        $perPage = $validated['per_page'] ?? 50;

        $query = $this->buildQuery($validated)
            ->with(['student', 'recorder'])
            ->orderByRaw("CASE WHEN status = 'unpaid' THEN 0 ELSE 1 END")
            ->orderBy(
                Student::select('last_name')->whereColumn('students.id', 'student_monthly_payments.student_id')->limit(1),
            );

        $summaryQuery = $this->buildQuery($validated);

        $totalSubscribers = (clone $summaryQuery)->distinct('student_id')->count('student_id');
        $totalCollected = (float) (clone $summaryQuery)->where('status', 'paid')->sum('amount');
        $totalOutstanding = (float) (clone $summaryQuery)->where('status', 'unpaid')->sum('amount');
        $totalAmount = $totalCollected + $totalOutstanding;
        $collectionRate = $totalAmount > 0 ? round(($totalCollected / $totalAmount) * 100, 2) : 0;

        $payments = $query->paginate($perPage);

        $payments->getCollection()->each(function ($payment): void {
            $payment->student?->append('full_name');
            $payment->recorder?->append('full_name');
        });

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

    public function export(ExportBillingReportRequest $request): BinaryFileResponse
    {
        $validated = $request->validated();

        $validated['school_month'] ??= SchoolMonth::fromMonthNumber(now()->month)?->value;
        $validated['year'] ??= now()->month >= 6 ? now()->year : now()->year - 1;
        $branch = app('active_branch');
        $year = $validated['year'];

        $payments = $this->buildQuery($validated)
            ->with(['student', 'recorder'])
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

    /**
     * The `year` filter is the year the school year starts, while a payment's `year` is the calendar
     * year of its month: January–March of school year Y are stored as Y + 1.
     *
     * @param  array<string, mixed>  $validated
     * @return Builder<StudentMonthlyPayment>
     */
    private function buildQuery(array $validated): Builder
    {
        $branchId = app('active_branch')->id;
        $studentIds = Student::where('branch_id', $branchId)->pluck('id');
        $status = $validated['status'] ?? null;
        $filteringByDate = isset($validated['recorded_from']) || isset($validated['recorded_to']);
        $schoolYear = (int) $validated['year'];

        return StudentMonthlyPayment::whereIn('student_id', $studentIds)
            ->when(
                isset($validated['school_month']),
                fn ($q) => $q->where('year', SchoolMonth::from($validated['school_month'])->calendarYearFor($schoolYear))
                    ->where('school_month', $validated['school_month']),
                fn ($q) => $q->where(fn ($q) => $q
                    ->where(fn ($a) => $a->whereIn('school_month', SchoolMonth::firstHalfValues())->where('year', $schoolYear))
                    ->orWhere(fn ($b) => $b->whereIn('school_month', SchoolMonth::secondHalfValues())->where('year', $schoolYear + 1))
                ),
            )
            ->when(
                $status === 'voided',
                fn ($q) => $q->where('status', 'voided'),
                fn ($q) => $q->where('status', '!=', 'voided')
                    ->when($status !== null, fn ($inner) => $inner->where('status', $status))
            )
            ->when(isset($validated['grade_level']), fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('grade_level', $validated['grade_level'])))
            ->when(isset($validated['recorded_by']), fn ($q) => $q->where('recorded_by', $validated['recorded_by']))
            ->when($filteringByDate, fn ($q) => $q->where(function ($inner) use ($validated, $status) {
                $inner->whereNotNull('recorded_at')
                    ->when(isset($validated['recorded_from']), fn ($d) => $d->whereDate('recorded_at', '>=', $validated['recorded_from']))
                    ->when(isset($validated['recorded_to']), fn ($d) => $d->whereDate('recorded_at', '<=', $validated['recorded_to']));

                if ($status !== 'paid') {
                    $inner->orWhereNull('recorded_at');
                }
            }))
            ->when(isset($validated['search']), function ($q) use ($validated) {
                $like = '%'.mb_strtolower($validated['search']).'%';
                $q->whereHas('student', fn ($sq) => $sq->where(function ($inner) use ($like) {
                    $inner->whereRaw('lower(first_name) like ?', [$like])
                        ->orWhereRaw('lower(last_name) like ?', [$like])
                        ->orWhereRaw('lower(student_number) like ?', [$like]);
                }));
            });
    }
}
