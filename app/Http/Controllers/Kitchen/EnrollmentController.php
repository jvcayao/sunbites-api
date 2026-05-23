<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchMonthlyAmount;
use App\Models\Student;
use App\Models\StudentContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EnrollmentController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('kitchen/enrollment/index', [
            'branches' => Branch::where('is_active', true)->get(['id', 'name', 'slug']),
            'gradeLevels' => config('sunbites.grade_levels'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'student_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('students')->where(fn ($q) => $q->where('branch_id', $request->branch_id)),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'grade_level' => ['required', 'string', 'in:'.implode(',', config('sunbites.grade_levels'))],
            'section' => ['nullable', 'string', 'max:100'],
            'birthday' => ['required', 'date', 'before:today'],
            'student_type' => ['required', Rule::enum(StudentType::class)],
            'photo' => ['nullable', 'file', 'mimes:jpeg,png,webp', 'max:2048'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'contacts' => ['required', 'array', 'min:1', 'max:3'],
            'contacts.*.full_name' => ['required', 'string', 'max:150'],
            'contacts.*.relationship' => ['required', 'string', 'max:100'],
            'contacts.*.phone' => ['required', 'string', 'max:30'],
            'contacts.*.address' => ['required', 'string', 'max:255'],
            'contacts.*.email' => ['required', 'email', 'max:150'],
            'signature' => ['required', 'string', 'max:255'],
            'permission_meals' => ['required', 'accepted'],
            'permission_dietary' => ['required', 'accepted'],
        ]);

        $validated['allergies'] = isset($validated['allergies']) ? strip_tags($validated['allergies']) : null;
        $validated['notes'] = isset($validated['notes']) ? strip_tags($validated['notes']) : null;

        $student = DB::transaction(function () use ($validated, $request) {
            $qrCode = Student::generateUniqueQrCode();

            $photoPath = null;
            if ($request->hasFile('photo')) {
                $photoPath = $request->file('photo')->store('photos/students', 'private');
            }

            $student = Student::create([
                'branch_id' => $validated['branch_id'],
                'student_number' => $validated['student_number'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'grade_level' => $validated['grade_level'],
                'section' => $validated['section'],
                'birthday' => $validated['birthday'],
                'photo_path' => $photoPath,
                'allergies' => $validated['allergies'],
                'notes' => $validated['notes'],
                'qr_code' => $qrCode,
                'student_type' => $validated['student_type'],
                'enrollment_status' => 'enrolled',
                'enrollment_date' => now()->toDateString(),
            ]);

            foreach ($validated['contacts'] as $index => $contact) {
                StudentContact::create([
                    'student_id' => $student->id,
                    'full_name' => $contact['full_name'],
                    'relationship' => $contact['relationship'],
                    'phone' => $contact['phone'],
                    'address' => $contact['address'],
                    'email' => $contact['email'],
                    'is_primary' => $index === 0,
                ]);
            }

            if ($student->student_type === StudentType::Subscription) {
                $this->seedMonthlyPayments($student);
            }

            activity('students')
                ->causedBy($request->user())
                ->performedOn($student)
                ->withProperties([
                    'student_type' => $student->student_type->value,
                    'branch' => $student->branch_id,
                ])
                ->log('students.enrolled');

            return $student;
        });

        return redirect()->route('kitchen.enrollment.success', $student)
            ->with('success', 'Student enrolled successfully.');
    }

    public function success(Student $student): Response
    {
        $student->load('contacts');

        return Inertia::render('kitchen/enrollment/success', [
            'student' => [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'student_number' => $student->student_number,
                'student_type' => $student->student_type->label(),
                'enrollment_date' => $student->enrollment_date->toDateString(),
                'qr_code' => $student->qr_code,
                'grade_level' => $student->grade_level,
                'section' => $student->section,
                'branch_id' => $student->branch_id,
            ],
        ]);
    }

    private function seedMonthlyPayments(Student $student): void
    {
        $branchAmounts = BranchMonthlyAmount::where('branch_id', $student->branch_id)
            ->pluck('amount', 'school_month')
            ->toArray();

        $configMonths = config('sunbites.school_months');

        foreach (SchoolMonth::cases() as $month) {
            $amount = $branchAmounts[$month->value]
                ?? $configMonths[$month->value]['amount']
                ?? 0;

            $student->monthlyPayments()->create([
                'school_month' => $month->value,
                'status' => 'unpaid',
                'amount' => $amount,
            ]);
        }
    }
}
