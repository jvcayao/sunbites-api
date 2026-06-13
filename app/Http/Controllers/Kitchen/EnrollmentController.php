<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\StudentContact;
use App\Models\SystemConfiguration;
use App\Services\EnrollmentService;
use App\Services\ParentProvisioningService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EnrollmentController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollmentService,
        private readonly ParentProvisioningService $provisioningService,
    ) {}

    public function index(): JsonResponse
    {
        $dailyRate = SystemConfiguration::getValue('daily_meal_rate', 135);
        $configMonths = config('sunbites.school_months');

        $defaults = collect(SchoolMonth::cases())->mapWithKeys(fn ($m) => [
            $m->value => [
                'month' => $m->value,
                'label' => $m->label(),
                'days' => $configMonths[$m->value]['days'] ?? 0,
                'default_amount' => ($configMonths[$m->value]['days'] ?? 0) * $dailyRate,
            ],
        ]);

        return response()->json([
            'branches' => Branch::where('is_active', true)->get(['id', 'name', 'slug']),
            'grade_levels' => config('sunbites.grade_levels'),
            'school_month_defaults' => $defaults,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'student_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('students')
                    ->where(fn ($q) => $q->where('branch_id', $request->branch_id))
                    ->whereNotNull('student_number'),
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
            'contacts.*.email' => ['nullable', 'email', 'max:150'],
            'signature' => ['required', 'string', 'max:255'],
            'permission_meals' => ['required', 'accepted'],
            'permission_dietary' => ['required', 'accepted'],
            'subscription_start_month' => ['required_if:student_type,subscription', Rule::enum(SchoolMonth::class)],
            'subscription_start_year' => ['required_if:student_type,subscription', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'subscription_end_month' => ['required_if:student_type,subscription', Rule::enum(SchoolMonth::class)],
            'subscription_end_year' => ['required_if:student_type,subscription', 'integer', 'digits:4', 'min:2020', 'max:2099'],
        ]);

        $studentType = StudentType::from($validated['student_type']);

        if ($studentType === StudentType::Subscription) {
            $start = Carbon::createFromDate($validated['subscription_start_year'], SchoolMonth::from($validated['subscription_start_month'])->toMonthNumber(), 1);
            $end = Carbon::createFromDate($validated['subscription_end_year'], SchoolMonth::from($validated['subscription_end_month'])->toMonthNumber(), 1);

            if ($end->lt($start)) {
                return response()->json(['errors' => ['subscription_end_month' => ['End month must be after start month.']]], 422);
            }
        }

        $validated['allergies'] = isset($validated['allergies']) ? strip_tags($validated['allergies']) : null;
        $validated['notes'] = isset($validated['notes']) ? strip_tags($validated['notes']) : null;

        $student = DB::transaction(function () use ($validated, $request) {
            $photoPath = null;
            if ($request->hasFile('photo')) {
                $photoPath = $request->file('photo')->store('photos/students', 'private');
            }

            $student = $this->enrollmentService->enroll(array_merge($validated, [
                'photo_path' => $photoPath,
            ]));

            foreach ($validated['contacts'] as $index => $contact) {
                StudentContact::create([
                    'student_id' => $student->id,
                    'full_name' => $contact['full_name'],
                    'relationship' => $contact['relationship'],
                    'phone' => $contact['phone'],
                    'address' => $contact['address'],
                    'email' => $contact['email'] ?? null,
                    'is_primary' => $index === 0,
                ]);

                if (! empty($contact['email'])) {
                    $this->provisioningService->provision(
                        $contact['email'],
                        $contact['full_name'],
                        $student->id,
                        $request->user()->id,
                    );
                }
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

        $subscriptionPeriod = null;
        if ($studentType === StudentType::Subscription) {
            $startLabel = SchoolMonth::from($validated['subscription_start_month'])->label().' '.$validated['subscription_start_year'];
            $endLabel = SchoolMonth::from($validated['subscription_end_month'])->label().' '.$validated['subscription_end_year'];
            $subscriptionPeriod = $startLabel.' – '.$endLabel;
        }

        return response()->json([
            'id' => $student->id,
            'student_number' => $student->student_number,
            'qr_code' => $student->qr_code,
            'full_name' => $student->full_name,
            'student_type' => $student->student_type->value,
            'enrollment_date' => $student->enrollment_date->toDateString(),
            'subscription_period' => $subscriptionPeriod,
        ], 201);
    }
}
