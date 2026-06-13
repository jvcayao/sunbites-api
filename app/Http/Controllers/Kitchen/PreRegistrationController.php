<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\PreRegistrationStatus;
use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Mail\PreRegistrationApprovedMail;
use App\Mail\PreRegistrationRejectedMail;
use App\Models\PreRegistration;
use App\Models\Student;
use App\Models\StudentContact;
use App\Models\SystemConfiguration;
use App\Services\EnrollmentService;
use App\Services\ParentProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class PreRegistrationController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollmentService,
        private readonly ParentProvisioningService $provisioningService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $statusFilter = $request->enum('status', PreRegistrationStatus::class) ?? PreRegistrationStatus::Pending;

        $preRegistrations = PreRegistration::with([
            'contacts' => fn ($q) => $q->where('is_primary', true),
        ])
            ->where('status', $statusFilter)
            ->latest()
            ->paginate(15);

        $items = $preRegistrations->getCollection()->map(fn (PreRegistration $preReg) => [
            'id' => $preReg->id,
            'student_name' => $preReg->full_name,
            'enrollment_type' => $preReg->enrollment_type,
            'status' => $preReg->status->value,
            'submitted_at' => $preReg->created_at,
            'expires_at' => $preReg->expires_at,
            'contact_name' => $preReg->contacts->first()?->full_name,
        ]);

        return response()->json([
            'data' => $items,
            'meta' => $this->paginationMeta($preRegistrations),
        ]);
    }

    public function show(PreRegistration $preRegistration): JsonResponse
    {
        $preRegistration->load('contacts', 'approvedBy', 'rejectedBy');

        $duplicateWarning = false;
        $existingStudentName = null;

        if ($preRegistration->student_number) {
            $existing = Student::withoutGlobalScopes()
                ->where('branch_id', $preRegistration->branch_id)
                ->where('student_number', $preRegistration->student_number)
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                $duplicateWarning = true;
                $existingStudentName = $existing->full_name;
            }
        }

        return response()->json([
            'data' => array_merge($preRegistration->toArray(), [
                'duplicate_warning' => $duplicateWarning,
                'existing_student_name' => $existingStudentName,
            ]),
        ]);
    }

    public function update(Request $request, PreRegistration $preRegistration): JsonResponse
    {
        if ($preRegistration->status !== PreRegistrationStatus::Pending) {
            return response()->json(['message' => 'Only pending pre-registrations can be edited.'], 422);
        }

        $validated = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'student_number' => ['nullable', 'string', 'max:50'],
            'grade_level' => ['sometimes', 'string', 'in:'.implode(',', config('sunbites.grade_levels'))],
            'section' => ['nullable', 'string', 'max:100'],
            'birthday' => ['sometimes', 'date', 'before:today'],
            'enrollment_type' => ['sometimes', 'in:subscription,non_subscription'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'subscription_start_month' => ['nullable', Rule::enum(SchoolMonth::class)],
            'subscription_start_year' => ['nullable', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'subscription_end_month' => ['nullable', Rule::enum(SchoolMonth::class)],
            'subscription_end_year' => ['nullable', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'signatory_name' => ['sometimes', 'string', 'max:255'],
            'contacts' => ['sometimes', 'array', 'min:1', 'max:3'],
            'contacts.*.id' => ['nullable', 'integer'],
            'contacts.*.full_name' => ['required_with:contacts', 'string', 'max:150'],
            'contacts.*.relationship' => ['required_with:contacts', 'string', 'max:100'],
            'contacts.*.phone' => ['required_with:contacts', 'string', 'max:30'],
            'contacts.*.address' => ['required_with:contacts', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:150'],
            'contacts.*.is_primary' => ['boolean'],
        ]);

        if (isset($validated['allergies'])) {
            $validated['allergies'] = strip_tags($validated['allergies']);
        }

        if (isset($validated['notes'])) {
            $validated['notes'] = strip_tags($validated['notes']);
        }

        $preRegistration->update(Arr::except($validated, ['contacts']));

        if (isset($validated['contacts'])) {
            $preRegistration->contacts()->delete();
            $preRegistration->contacts()->createMany($validated['contacts']);
        }

        return response()->json(['message' => 'Pre-registration updated.', 'data' => $preRegistration->fresh(['contacts'])]);
    }

    public function approve(PreRegistration $preRegistration): JsonResponse
    {
        if ($preRegistration->status !== PreRegistrationStatus::Pending) {
            return response()->json(['message' => 'Only pending pre-registrations can be approved.'], 422);
        }

        $result = DB::transaction(function () use ($preRegistration) {
            $locked = PreRegistration::lockForUpdate()->find($preRegistration->id);

            abort_if(
                $locked->status !== PreRegistrationStatus::Pending,
                422,
                'Only pending pre-registrations can be approved.'
            );

            if ($locked->student_number) {
                $duplicate = Student::withoutGlobalScopes()
                    ->where('branch_id', $locked->branch_id)
                    ->where('student_number', $locked->student_number)
                    ->whereNull('deleted_at')
                    ->exists();

                abort_if($duplicate, 422, 'A student with this student number already exists. Please resolve the duplicate before approving.');
            }

            $student = $this->enrollmentService->enroll($locked->toEnrollmentData());

            $locked->load('contacts');

            foreach ($locked->contacts as $contact) {
                StudentContact::create([
                    'student_id' => $student->id,
                    'full_name' => $contact->full_name,
                    'relationship' => $contact->relationship,
                    'phone' => $contact->phone,
                    'address' => $contact->address,
                    'email' => $contact->email,
                    'is_primary' => $contact->is_primary,
                ]);

                if ($contact->is_primary && $contact->email) {
                    $this->provisioningService->provision(
                        $contact->email,
                        $contact->full_name,
                        $student->id,
                        auth()->id(),
                    );
                }
            }

            $locked->update([
                'status' => PreRegistrationStatus::Approved,
                'approved_by' => auth()->id(),
                'processed_at' => now(),
            ]);

            activity('pre_registrations')
                ->causedBy(auth()->user())
                ->performedOn($locked)
                ->log('pre-registration.approved');

            return [$student, $locked];
        });

        [$student, $locked] = $result;

        $primaryEmail = $locked->contacts->firstWhere('is_primary', true)?->email
            ?? $locked->contacts->first()?->email;

        if ($primaryEmail) {
            Mail::to($primaryEmail)->queue(new PreRegistrationApprovedMail($locked, $student));
        }

        return response()->json([
            'message' => 'Pre-registration approved. Student enrolled successfully.',
            'data' => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'full_name' => $student->full_name,
                'qr_code' => $student->qr_code,
            ],
        ]);
    }

    public function reject(Request $request, PreRegistration $preRegistration): JsonResponse
    {
        if ($preRegistration->status !== PreRegistrationStatus::Pending) {
            return response()->json(['message' => 'Only pending pre-registrations can be rejected.'], 422);
        }

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $preRegistration->update([
            'status' => PreRegistrationStatus::Rejected,
            'rejected_by' => auth()->id(),
            'rejection_reason' => $validated['rejection_reason'],
            'processed_at' => now(),
        ]);

        $preRegistration->load('contacts');

        $primaryEmail = $preRegistration->contacts->firstWhere('is_primary', true)?->email
            ?? $preRegistration->contacts->first()?->email;

        if ($primaryEmail) {
            Mail::to($primaryEmail)->queue(new PreRegistrationRejectedMail($preRegistration));
        }

        return response()->json(['message' => 'Pre-registration rejected.']);
    }

    public function reactivate(PreRegistration $preRegistration): JsonResponse
    {
        if ($preRegistration->status !== PreRegistrationStatus::Expired) {
            return response()->json(['message' => 'Only expired pre-registrations can be reactivated.'], 422);
        }

        $expiryDays = SystemConfiguration::getValue('pre_registration_expiry_days', 30);

        $preRegistration->update([
            'status' => PreRegistrationStatus::Pending,
            'expires_at' => now()->addDays($expiryDays),
        ]);

        activity('pre_registrations')
            ->causedBy(auth()->user())
            ->performedOn($preRegistration)
            ->log('pre-registration.reactivated');

        return response()->json(['message' => 'Pre-registration reactivated.']);
    }
}
