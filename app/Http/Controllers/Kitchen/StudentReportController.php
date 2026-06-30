<?php

namespace App\Http\Controllers\Kitchen;

use App\Exports\StudentsExport;
use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branchId = app('active_branch')->id;
        $perPage = $validated['per_page'] ?? 25;

        $query = Student::where('branch_id', $branchId)
            ->with(['wallet'])
            ->when(filled($validated['status'] ?? null), fn ($q) => $q->where('enrollment_status', $validated['status']))
            ->when(filled($validated['grade'] ?? null), fn ($q) => $q->where('grade_level', $validated['grade']))
            ->when(filled($validated['type'] ?? null), fn ($q) => $q->where('student_type', $validated['type']))
            ->when(filled($validated['search'] ?? null), fn ($q) => $this->applySearch($q, $validated['search']))
            ->orderBy('last_name')
            ->orderBy('first_name');

        $totalEnrolled = Student::where('branch_id', $branchId)->where('enrollment_status', 'enrolled')->count();

        $byGrade = Student::where('branch_id', $branchId)
            ->selectRaw('grade_level, COUNT(*) as count')
            ->groupBy('grade_level')
            ->pluck('count', 'grade_level');

        $byStatus = Student::where('branch_id', $branchId)
            ->selectRaw('enrollment_status, COUNT(*) as count')
            ->groupBy('enrollment_status')
            ->pluck('count', 'enrollment_status');

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
        ]);

        return response()->json([
            'data' => $rows->items(),
            'meta' => $this->paginationMeta($rows),
            'summary' => [
                'total' => $totalEnrolled,
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
        ]);

        $branch = app('active_branch');

        $students = Student::where('branch_id', $branch->id)
            ->with([
                'contacts' => fn ($q) => $q->where('is_primary', true),
                'wallet',
            ])
            ->when(filled($validated['status'] ?? null), fn ($q) => $q->where('enrollment_status', $validated['status']))
            ->when(filled($validated['grade'] ?? null), fn ($q) => $q->where('grade_level', $validated['grade']))
            ->when(filled($validated['type'] ?? null), fn ($q) => $q->where('student_type', $validated['type']))
            ->when(filled($validated['search'] ?? null), fn ($q) => $this->applySearch($q, $validated['search']))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $filename = "students-{$branch->slug}-".now()->format('Y-m-d').'.xlsx';

        return Excel::download(new StudentsExport($students), $filename);
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
}
