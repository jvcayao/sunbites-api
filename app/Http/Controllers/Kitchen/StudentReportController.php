<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Exports\StudentsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ExportStudentReportRequest;
use App\Http\Requests\Kitchen\ListStudentReportRequest;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudentReportController extends Controller
{
    public function index(ListStudentReportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $branchId = app('active_branch')->id;
        $yearStart = $this->schoolYearStart();
        $perPage = $validated['per_page'] ?? 25;

        $baseQuery = $this->buildStudentQuery($branchId, $validated, $this->appliesPaymentFilter($validated));

        $query = (clone $baseQuery)
            ->with([
                'wallet',
                'monthlyPayments' => fn ($q) => $q->whereIn('year', [$yearStart, $yearStart + 1]),
            ])
            ->orderBy('last_name')
            ->orderBy('first_name');

        $total = (clone $baseQuery)
            ->when(
                ! filled($validated['status'] ?? null),
                fn ($q) => $q->where('enrollment_status', 'enrolled')
            )
            ->count();

        $byGrade = (clone $baseQuery)->selectRaw('grade_level, COUNT(*) as count')->groupBy('grade_level')->pluck('count', 'grade_level');
        $byStatus = (clone $baseQuery)->selectRaw('enrollment_status, COUNT(*) as count')->groupBy('enrollment_status')->pluck('count', 'enrollment_status');

        $paginator = $query->paginate($perPage);

        $rows = $paginator->through(fn (Student $student) => [
            'id' => $student->id,
            'full_name' => $student->full_name,
            'student_number' => $student->student_number,
            'grade_level' => $student->grade_level,
            'section' => $student->section,
            'status' => $student->enrollment_status?->value,
            'wallet_balance' => (float) ($student->wallet?->balanceFloat ?? 0),
            'total_spent' => (float) $student->total_spent,
            'notes' => $student->notes,
            'allergies' => $student->allergies,
            'payment_history' => $student->student_type?->value === 'subscription'
                ? $this->buildPaymentHistory($student, $yearStart)
                : null,
        ]);

        return response()->json([
            'data' => $rows->items(),
            'meta' => $this->paginationMeta($rows),
            'summary' => [
                'total' => $total,
                'grade_breakdown' => $byGrade,
                'status_breakdown' => $byStatus,
            ],
        ]);
    }

    public function export(ExportStudentReportRequest $request): BinaryFileResponse
    {
        $validated = $request->validated();

        $branch = app('active_branch');

        $students = $this->buildStudentQuery($branch->id, $validated, $this->appliesPaymentFilter($validated))
            ->with([
                'contacts' => fn ($q) => $q->where('is_primary', true),
                'wallet',
            ])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $filename = "students-{$branch->slug}-".now()->format('Y-m-d').'.xlsx';

        return Excel::download(new StudentsExport($students), $filename);
    }

    private function buildStudentQuery(int $branchId, array $validated, bool $applyPayment): Builder
    {
        return Student::where('branch_id', $branchId)
            ->when(filled($validated['status'] ?? null), fn ($q) => $q->where('enrollment_status', $validated['status']))
            ->when(filled($validated['grade'] ?? null), fn ($q) => $q->where('grade_level', $validated['grade']))
            ->when(filled($validated['type'] ?? null), fn ($q) => $q->where('student_type', $validated['type']))
            ->when(filled($validated['search'] ?? null), fn ($q) => $this->applySearch($q, $validated['search']))
            ->when($applyPayment, fn ($q) => $this->applyPaymentFilter(
                $q,
                $validated['payment_status'],
                $validated['payment_from'] ?? 'june',
                $validated['payment_to'] ?? 'march',
            ));
    }

    /**
     * The payment filter only applies to subscription students.
     *
     * @param  array<string, mixed>  $validated
     */
    private function appliesPaymentFilter(array $validated): bool
    {
        return filled($validated['payment_status'] ?? null)
            && ($validated['type'] ?? null) === 'subscription';
    }

    private function schoolYearStart(): int
    {
        $now = now();

        return $now->month >= 6 ? $now->year : $now->year - 1;
    }

    private function monthsInRange(string $from, string $to): array
    {
        $cases = array_column(SchoolMonth::cases(), 'value');
        $fromIdx = array_search($from, $cases, true);
        $toIdx = array_search($to, $cases, true);

        if ($fromIdx === false || $toIdx === false || $toIdx < $fromIdx) {
            return [$from];
        }

        return array_slice($cases, $fromIdx, $toIdx - $fromIdx + 1);
    }

    private function applyPaymentFilter(Builder $query, string $status, string $from, string $to): void
    {
        $yearStart = $this->schoolYearStart();
        $monthYearPairs = array_map(fn (string $m) => [
            'month' => $m,
            'year' => SchoolMonth::from($m)->calendarYearFor($yearStart),
        ], $this->monthsInRange($from, $to));

        if ($status === 'paid') {
            foreach ($monthYearPairs as ['month' => $m, 'year' => $y]) {
                $query->whereHas('monthlyPayments', fn ($q) => $q->where('school_month', $m)->where('year', $y)->where('status', 'paid')
                );
            }
        } else {
            $query->whereHas('monthlyPayments', function ($q) use ($monthYearPairs, $status) {
                $q->where('status', $status)
                    ->where(function ($inner) use ($monthYearPairs) {
                        foreach ($monthYearPairs as ['month' => $m, 'year' => $y]) {
                            $inner->orWhere(fn ($c) => $c->where('school_month', $m)->where('year', $y));
                        }
                    });
            });
        }
    }

    private function applySearch(Builder $query, string $term): void
    {
        $query->where(function ($q) use ($term) {
            $q->whereContainsLiteral('first_name', $term)
                ->whereContainsLiteral('last_name', $term, 'or')
                ->whereContainsLiteral("CONCAT(first_name, ' ', last_name)", $term, 'or')
                ->whereContainsLiteral('student_number', $term, 'or')
                ->whereContainsLiteral('section', $term, 'or');
        });
    }

    private function buildPaymentHistory(Student $student, int $yearStart): array
    {
        $payments = $student->monthlyPayments
            ->keyBy(fn ($p) => $p->school_month->value.'-'.$p->year);

        return array_map(function (string $month) use ($yearStart, $payments) {
            $schoolMonth = SchoolMonth::from($month);
            $year = $schoolMonth->calendarYearFor($yearStart);
            $payment = $payments->get($month.'-'.$year);

            return [
                'month' => $month,
                'month_label' => $schoolMonth->label(),
                'year' => $year,
                'status' => $payment?->status ?? 'no_record',
            ];
        }, array_column(SchoolMonth::cases(), 'value'));
    }
}
