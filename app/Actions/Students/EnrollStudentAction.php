<?php

namespace App\Actions\Students;

use App\Models\Student;
use App\Models\StudentContact;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\ParentProvisioningService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Enrol a student with their contacts in one transaction, and give every contact with an
 * email a portal account.
 *
 * The student is created in `$validated['branch_id']`, the branch picked on the enrolment
 * form, which may differ from the active branch. Pre-registration approval has its own
 * contact and provisioning rules on purpose; do not merge the two.
 */
class EnrollStudentAction
{
    public function __construct(
        private readonly EnrollmentService $enrollmentService,
        private readonly ParentProvisioningService $provisioningService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  EnrollStudentRequest::validated(), with allergies and notes already stripped
     */
    public function execute(array $validated, ?UploadedFile $photo, User $enrolledBy): Student
    {
        return DB::transaction(function () use ($validated, $photo, $enrolledBy) {
            $photoPath = $photo?->store('photos/students', 'private');

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
                        $enrolledBy->id,
                    );
                }
            }

            activity('students')
                ->causedBy($enrolledBy)
                ->performedOn($student)
                ->withProperties([
                    'student_type' => $student->student_type->value,
                    'branch' => $student->branch_id,
                ])
                ->log('students.enrolled');

            return $student;
        });
    }
}
