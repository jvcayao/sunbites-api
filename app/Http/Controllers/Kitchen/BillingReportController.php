<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Exports\BillingReportExport;
use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BillingReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(array_merge($this->filterRules(), [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]));

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

        collect($payments->items())->each(function ($payment): void {
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

    public function export(Request $request): BinaryFileResponse
    {
        $validated = $request->validate($this->filterRules());

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

    private function schoolMonthValues(): array
    {
        return collect(SchoolMonth::cases())->map->value->toArray();
    }

    private function filterRules(): array
    {
        return [
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'school_month' => ['nullable', 'string', Rule::in($this->schoolMonthValues())],
            'status' => ['nullable', 'string', 'in:paid,unpaid'],
            'grade_level' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:100'],
            'recorded_by' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    private function buildQuery(array $validated): Builder
    {
        $branchId = app('active_branch')->id;
        $studentIds = Student::where('branch_id', $branchId)->pluck('id');

        return StudentMonthlyPayment::whereIn('student_id', $studentIds)
            ->where('year', $validated['year'])
            ->when(isset($validated['school_month']), fn ($q) => $q->where('school_month', $validated['school_month']))
            ->when(isset($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->when(isset($validated['grade_level']), fn ($q) => $q->whereHas('student', fn ($sq) => $sq->where('grade_level', $validated['grade_level'])))
            ->when(isset($validated['recorded_by']), fn ($q) => $q->where('recorded_by', $validated['recorded_by']))
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
