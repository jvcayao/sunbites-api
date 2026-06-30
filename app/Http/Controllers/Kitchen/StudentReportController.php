<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Exports\StudentsExport;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudentReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'grade' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:100'],
            'payment_status' => ['nullable', 'string', 'in:paid,unpaid,voided'],
            'payment_from' => ['nullable', 'string', Rule::enum(SchoolMonth::class)],
            'payment_to' => ['nullable', 'string', Rule::enum(SchoolMonth::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branchId = app('active_branch')->id;
        $yearStart = $this->schoolYearStart();
        $perPage = $validated['per_page'] ?? 25;

        $applyPayment = filled($validated['payment_status'] ?? null)
            && ($validated['type'] ?? null) === 'subscription';

        $baseQuery = $this->buildStudentQuery($branchId, $validated, $applyPayment);

        $query = (clone $baseQuery)
            ->with([
                'wallet',
                'monthlyPayments' => fn ($q) => $q->whereIn('year', [$yearStart, $yearStart + 1]),
            ])
            ->orderBy('last_name')
            ->orderBy('first_name');

        $summaryBase = clone $baseQuery;

        $total = (clone $summaryBase)
            ->when(
                ! filled($validated['status'] ?? null),
                fn ($q) => $q->where('enrollment_status', 'enrolled')
            )
            ->count();

        $byGrade = (clone $summaryBase)->selectRaw('grade_level, COUNT(*) as count')->groupBy('grade_level')->pluck('count', 'grade_level');
        $byStatus = (clone $summaryBase)->selectRaw('enrollment_status, COUNT(*) as count')->groupBy('enrollment_status')->pluck('count', 'enrollment_status');

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

    public function export(Request $request): BinaryFileResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'grade' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:100'],
            'payment_status' => ['nullable', 'string', 'in:paid,unpaid,voided'],
            'payment_from' => ['nullable', 'string', Rule::enum(SchoolMonth::class)],
            'payment_to' => ['nullable', 'string', Rule::enum(SchoolMonth::class)],
        ]);

        $branch = app('active_branch');

        $applyPayment = filled($validated['payment_status'] ?? null)
            && ($validated['type'] ?? null) === 'subscription';

        $students = $this->buildStudentQuery($branch->id, $validated, $applyPayment)
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
            'year' => SchoolMonth::from($m)->toMonthNumber() <= 3 ? $yearStart + 1 : $yearStart,
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
            $q->where('first_name', 'like', "%{$term}%")
                ->orWhere('last_name', 'like', "%{$term}%")
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$term}%"])
                ->orWhere('student_number', 'like', "%{$term}%")
                ->orWhere('section', 'like', "%{$term}%");
        });
    }

    private function buildPaymentHistory(Student $student, int $yearStart): array
    {
        $payments = $student->monthlyPayments
            ->keyBy(fn ($p) => $p->school_month->value.'-'.$p->year);

        return array_map(function (string $month) use ($yearStart, $payments) {
            $schoolMonth = SchoolMonth::from($month);
            $year = $schoolMonth->toMonthNumber() <= 3 ? $yearStart + 1 : $yearStart;
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
