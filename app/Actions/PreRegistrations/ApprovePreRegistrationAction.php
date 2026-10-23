<?php

namespace App\Actions\PreRegistrations;

use App\Enums\PreRegistrationStatus;
use App\Mail\PreRegistrationApprovedMail;
use App\Models\PreRegistration;
use App\Models\Student;
use App\Models\StudentContact;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\ParentProvisioningService;
use App\Services\StudentDuplicateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Turn a pending pre-registration into an enrolled student, then email the family.
 *
 * The record is re-read without the branch scope under a lock, so the student is enrolled in
 * the pre-registration's own branch. Unlike staff enrolment, only the primary contact with an
 * email gets a portal account; do not merge this with EnrollStudentAction.
 */
class ApprovePreRegistrationAction
{
    public function __construct(
        private readonly EnrollmentService $enrollmentService,
        private readonly ParentProvisioningService $provisioningService,
        private readonly StudentDuplicateService $duplicateService,
    ) {}

    public function execute(PreRegistration $preRegistration, User $approver): Student
    {
        [$student, $locked] = DB::transaction(function () use ($preRegistration, $approver) {
            $locked = PreRegistration::withoutBranch()->lockForUpdate()->find($preRegistration->id);

            abort_if($locked === null, 404, 'Pre-registration not found.');

            abort_if(
                $locked->status !== PreRegistrationStatus::Pending,
                422,
                'Only pending pre-registrations can be approved.'
            );

            // PRIMARY: name + birthday — always runs, regardless of student_number
            abort_if(
                $this->duplicateService->isEnrolledStudent(
                    $locked->branch_id,
                    $locked->first_name,
                    $locked->last_name,
                    $locked->birthday->toDateString(),
                ),
                422,
                'A student with this name and birthday is already enrolled. Please resolve the duplicate before approving.'
            );

            // SUPPLEMENTARY: student_number — only when provided
            if ($locked->student_number) {
                $numberDuplicate = Student::withoutGlobalScopes()
                    ->where('branch_id', $locked->branch_id)
                    ->where('student_number', $locked->student_number)
                    ->whereNull('deleted_at')
                    ->exists();

                abort_if($numberDuplicate, 422, 'A student with this student number already exists. Please resolve the duplicate before approving.');
            }

            $student = $this->enrollmentService->enroll($locked->toEnrollmentData());

            $locked->load('contacts');

            foreach ($locked->contacts as $contact) {
                StudentContact::create([
                    'student_id' => $student->id,
                    ...$contact->only(['full_name', 'relationship', 'phone', 'address', 'email', 'is_primary']),
                ]);

                if ($contact->is_primary && $contact->email) {
                    $this->provisioningService->provision(
                        $contact->email,
                        $contact->full_name,
                        $student->id,
                        $approver->id,
                    );
                }
            }

            $locked->update([
                'status' => PreRegistrationStatus::Approved,
                'approved_by' => $approver->id,
                'processed_at' => now(),
            ]);

            activity('pre_registrations')
                ->causedBy($approver)
                ->performedOn($locked)
                ->log('pre-registration.approved');

            return [$student, $locked];
        });

        $primaryEmail = $locked->contacts->firstWhere('is_primary', true)?->email
            ?? $locked->contacts->first()?->email;

        if ($primaryEmail) {
            Mail::to($primaryEmail)->queue(new PreRegistrationApprovedMail($locked, $student));
        }

        return $student;
    }
}
