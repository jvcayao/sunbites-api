<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class StudentController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Student::with([
            'contacts' => fn ($q) => $q->where('is_primary', true),
            'monthlyPayments',
        ])
            ->when($request->search, function ($q, $search) {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                $q->where(function ($q) use ($escaped) {
                    $q->where('first_name', 'ilike', "%{$escaped}%")
                        ->orWhere('last_name', 'ilike', "%{$escaped}%")
                        ->orWhere('student_number', 'ilike', "%{$escaped}%");
                });
            })
            ->when($request->grade, fn ($q, $grade) => $q->where('grade_level', $grade))
            ->when($request->status, fn ($q, $status) => $q->where('enrollment_status', $status))
            ->when($request->type && $request->type !== 'all', fn ($q) => $q->where('student_type', $request->type));

        if ($request->type === 'subscription' && $request->month) {
            $month = $request->month;
            $paymentStatus = $request->payment_status;

            $query->whereHas('monthlyPayments', function ($q) use ($month, $paymentStatus) {
                $q->where('school_month', $month);
                if ($paymentStatus) {
                    $q->where('status', $paymentStatus);
                }
            });
        }

        $students = $query->orderBy('last_name')->orderBy('first_name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('kitchen/students/index', [
            'students' => StudentResource::collection($students),
            'filters' => $request->only(['search', 'grade', 'status', 'type', 'month', 'payment_status']),
            'gradeLevels' => config('sunbites.grade_levels'),
            'enrollmentStatuses' => $this->enrollmentStatusOptions(),
        ]);
    }

    public function show(Student $student): Response
    {
        $student->load(['contacts', 'wallet', 'monthlyPayments']);

        $walletTransactions = $student->wallet
            ? $student->wallet->transactions()->latest()->take(20)->get()
            : collect();

        return Inertia::render('kitchen/students/show', [
            'student' => new StudentResource($student),
            'walletTransactions' => $walletTransactions,
            'gradeLevels' => config('sunbites.grade_levels'),
            'enrollmentStatuses' => $this->enrollmentStatusOptions(),
            'activityLogs' => Inertia::defer(fn () => Activity::where('subject_type', Student::class)
                ->where('subject_id', $student->id)
                ->latest()
                ->take(50)
                ->get()
                ->map(fn ($log) => [
                    'id' => $log->id,
                    'description' => $log->description,
                    'causer' => $log->causer?->full_name ?? 'System',
                    'properties' => $log->properties,
                    'created_at' => $log->created_at->toDateTimeString(),
                ])),
        ]);
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'grade_level' => ['required', 'string', 'in:'.implode(',', config('sunbites.grade_levels'))],
            'section' => ['nullable', 'string', 'max:100'],
            'birthday' => ['required', 'date', 'before:today'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'file', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        $validated['allergies'] = isset($validated['allergies']) ? strip_tags($validated['allergies']) : null;
        $validated['notes'] = isset($validated['notes']) ? strip_tags($validated['notes']) : null;

        DB::transaction(function () use ($validated, $request, $student) {
            if ($request->hasFile('photo')) {
                if ($student->photo_path) {
                    Storage::disk('private')->delete($student->photo_path);
                }
                $validated['photo_path'] = $request->file('photo')->store('photos/students', 'private');
            }

            unset($validated['photo']);
            $student->update($validated);

            activity('students')
                ->causedBy($request->user())
                ->performedOn($student)
                ->log('students.updated');
        });

        return back()->with('success', 'Student profile updated.');
    }

    public function destroy(Request $request, Student $student): RedirectResponse
    {
        $student->delete();

        activity('students')
            ->causedBy($request->user())
            ->performedOn($student)
            ->log('students.deleted');

        return redirect()->route('kitchen.students.index')
            ->with('success', 'Student removed.');
    }

    public function updateStatus(Request $request, Student $student): RedirectResponse
    {
        $validated = $request->validate([
            'enrollment_status' => ['required', Rule::enum(EnrollmentStatus::class)],
            'reason' => [
                Rule::when(
                    EnrollmentStatus::from($request->enrollment_status)->requiresReason(),
                    ['required', 'string', 'max:500'],
                    ['nullable'],
                ),
            ],
        ]);

        $oldStatus = $student->enrollment_status->value;

        $student->update(['enrollment_status' => $validated['enrollment_status']]);

        activity('students')
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties([
                'old_status' => $oldStatus,
                'new_status' => $validated['enrollment_status'],
                'reason' => $validated['reason'] ?? null,
            ])
            ->log('students.status_changed');

        return back()->with('success', 'Enrollment status updated.');
    }

    public function regenerateQr(Request $request, Student $student): RedirectResponse
    {
        $newCode = Student::generateUniqueQrCode();

        $student->update(['qr_code' => $newCode]);

        activity('students')
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties(['old_qr_redacted' => true, 'new_qr_redacted' => true, 'performed_by' => $request->user()->id])
            ->log('students.qr_regenerated');

        return back()->with('success', 'QR code regenerated.');
    }

    /** @return array<int, array{value: string, label: string, requiresReason: bool}> */
    private function enrollmentStatusOptions(): array
    {
        return collect(EnrollmentStatus::cases())->map(fn (EnrollmentStatus $status) => [
            'value' => $status->value,
            'label' => $status->label(),
            'requiresReason' => $status->requiresReason(),
        ])->all();
    }
}
