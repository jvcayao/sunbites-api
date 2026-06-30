<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\EnrollmentStatus;
use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->deleted
            ? Student::onlyTrashed()
            : Student::query();

        $query->with([
            'contacts' => fn ($q) => $q->where('is_primary', true),
            'monthlyPayments',
        ])
            ->when($request->search, function ($q, $search) {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                $q->where(function ($q) use ($escaped) {
                    $q->where('first_name', 'like', "%{$escaped}%")
                        ->orWhere('last_name', 'like', "%{$escaped}%")
                        ->orWhere('student_number', 'like', "%{$escaped}%");
                });
            })
            ->when($request->grade, fn ($q, $grade) => $q->where('grade_level', $grade));

        if (! $request->deleted) {
            $query
                ->when($request->status, fn ($q, $status) => $q->where('enrollment_status', $status))
                ->when($request->type && $request->type !== 'all', fn ($q) => $q->where('student_type', $request->type));

            if ($request->type === 'subscription' && $request->month) {
                $month = $request->month;
                $paymentStatus = $request->payment_status;
                $year = $request->year;

                $query->whereHas('monthlyPayments', function ($q) use ($month, $paymentStatus, $year) {
                    $q->where('school_month', $month);
                    if ($paymentStatus) {
                        $q->where('status', $paymentStatus);
                    }
                    if ($year) {
                        $q->where('year', $year);
                    }
                });
            }
        }

        $students = $query->orderBy('last_name')->orderBy('first_name')
            ->paginate(20)
            ->withQueryString();

        return response()->json(StudentResource::collection($students)->response()->getData(true));
    }

    public function show(Student $student): JsonResponse
    {
        $student->load(['contacts', 'wallet', 'monthlyPayments']);

        $walletTransactions = $student->wallet
            ? $student->wallet->transactions()->latest()->take(20)->get()
                ->map(fn ($tx) => [
                    'id' => $tx->id,
                    'type' => $tx->type?->value ?? $tx->type,
                    'amount' => $tx->amountFloat,
                    'note' => $tx->meta['note'] ?? null,
                    'created_at' => $tx->created_at->toDateTimeString(),
                ])
            : collect();

        $activityLogs = Activity::with('causer')
            ->where('subject_type', Student::class)
            ->where('subject_id', $student->id)
            ->latest()
            ->take(50)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'description' => $log->description,
                'causer_name' => $log->causer?->full_name,
                'properties' => $log->properties,
                'created_at' => $log->created_at->toDateTimeString(),
            ]);

        return response()->json([
            'student' => new StudentResource($student),
            'subscription_monthly_status' => $student->currentMonthSubscriptionStatus(),
            'wallet_transactions' => $walletTransactions,
            'activity_logs' => $activityLogs,
        ]);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'student_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('students')
                    ->where(fn ($q) => $q->where('branch_id', $student->branch_id))
                    ->ignore($student->id)
                    ->whereNotNull('student_number'),
            ],
            'grade_level' => ['required', 'string', 'in:'.implode(',', config('sunbites.grade_levels'))],
            'section' => ['nullable', 'string', 'max:100'],
            'birthday' => ['required', 'date', 'before:today'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'photo' => ['nullable', 'file', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        $validated['allergies'] = isset($validated['allergies']) ? strip_tags($validated['allergies']) : null;
        $validated['notes'] = isset($validated['notes']) ? strip_tags($validated['notes']) : null;

        DB::transaction(function () use ($validated, $request, $student): void {
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

        return response()->json(new StudentResource($student->fresh()));
    }

    public function uploadPhoto(Request $request, Student $student): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        if ($student->photo_path) {
            Storage::disk('private')->delete($student->photo_path);
        }

        $student->photo_path = $request->file('photo')->store('photos/students', 'private');
        $student->save();

        activity('students')
            ->causedBy($request->user())
            ->performedOn($student)
            ->log('students.photo_updated');

        return response()->json(new StudentResource($student->fresh()));
    }

    public function photo(Student $student): StreamedResponse
    {
        abort_unless(
            $student->photo_path && Storage::disk('private')->exists($student->photo_path),
            404,
            'Photo not found.'
        );

        return Storage::disk('private')->response($student->photo_path);
    }

    public function destroy(Request $request, Student $student): JsonResponse
    {
        $student->delete();

        activity('students')
            ->causedBy($request->user())
            ->performedOn($student)
            ->log('students.deleted');

        return response()->json(['message' => 'Student removed.']);
    }

    public function restore(Request $request, Student $student): JsonResponse
    {
        if (! $student->trashed()) {
            return response()->json(['message' => 'Student is not deleted.'], 422);
        }

        $student->restore();

        activity('students')
            ->causedBy($request->user())
            ->performedOn($student)
            ->log('students.restored');

        return response()->json(new StudentResource($student->fresh()));
    }

    public function updateStatus(Request $request, Student $student): JsonResponse
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

        return response()->json(new StudentResource($student->fresh()));
    }

    public function orders(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = $student->orders()
            ->with('items')
            ->latest()
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'data' => OrderResource::collection($orders->items()),
            'meta' => $this->paginationMeta($orders),
        ]);
    }

    public function updateType(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'student_type' => ['required', Rule::enum(StudentType::class)],
        ]);

        abort_if(
            $student->student_type === StudentType::Subscription
                && StudentType::from($validated['student_type']) === StudentType::NonSubscription,
            422,
            'Subscription students must be downgraded via the dedicated downgrade endpoint.'
        );

        $oldType = $student->student_type->value;

        $student->update(['student_type' => StudentType::from($validated['student_type'])]);

        activity('students')
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties([
                'old_type' => $oldType,
                'new_type' => $validated['student_type'],
            ])
            ->log('students.type_changed');

        return response()->json(new StudentResource($student->fresh()));
    }

    public function regenerateQr(Request $request, Student $student): JsonResponse
    {
        $newCode = Student::generateUniqueQrCode();

        $student->update(['qr_code' => $newCode]);

        activity('students')
            ->causedBy($request->user())
            ->performedOn($student)
            ->withProperties(['old_qr_redacted' => true, 'new_qr_redacted' => true])
            ->log('students.qr_regenerated');

        return response()->json(['qr_code' => $newCode]);
    }
}
