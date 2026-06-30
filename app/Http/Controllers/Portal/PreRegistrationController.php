<?php

namespace App\Http\Controllers\Portal;

use App\Enums\PreRegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\PreRegistration;
use App\Models\SystemConfiguration;
use App\Services\StudentDuplicateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreRegistrationController extends Controller
{
    public function __construct(
        private readonly StudentDuplicateService $duplicateService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'student_number' => ['nullable', 'string', 'max:50'],
            'grade_level' => ['required', 'string', 'in:'.implode(',', config('sunbites.grade_levels'))],
            'section' => ['nullable', 'string', 'max:100'],
            'birthday' => ['required', 'date', 'before:today'],
            'enrollment_type' => ['required', 'in:subscription,non_subscription'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'subscription_start_month' => ['required_if:enrollment_type,subscription', 'nullable', 'string'],
            'subscription_start_year' => ['required_if:enrollment_type,subscription', 'nullable', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'subscription_end_month' => ['required_if:enrollment_type,subscription', 'nullable', 'string'],
            'subscription_end_year' => ['required_if:enrollment_type,subscription', 'nullable', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'signatory_name' => ['required', 'string', 'max:255'],
            'contacts' => ['required', 'array', 'min:1', 'max:3'],
            'contacts.*.full_name' => ['required', 'string', 'max:150'],
            'contacts.*.relationship' => ['required', 'string', 'max:100'],
            'contacts.*.phone' => ['required', 'string', 'max:30'],
            'contacts.*.address' => ['required', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:150'],
            'contacts.*.is_primary' => ['boolean'],
        ]);

        $branchId = $validated['branch_id'];
        $firstName = $validated['first_name'];
        $lastName = $validated['last_name'];
        $birthday = $validated['birthday'];

        if ($this->duplicateService->isEnrolledStudent($branchId, $firstName, $lastName, $birthday)) {
            return response()->json([
                'message' => 'A student with these details is already enrolled.',
                'errors' => [
                    'student' => [
                        "A student named {$firstName} {$lastName} (born {$birthday}) is already enrolled at this branch.",
                    ],
                ],
            ], 422);
        }

        if ($this->duplicateService->hasPendingPreRegistration($branchId, $firstName, $lastName, $birthday)) {
            return response()->json([
                'message' => 'A pre-registration for this student is already pending review.',
                'errors' => [
                    'student' => [
                        "A pre-registration for {$firstName} {$lastName} (born {$birthday}) is already pending review.",
                    ],
                ],
            ], 422);
        }

        $primaryContact = collect($validated['contacts'])->firstWhere('is_primary', true)
            ?? $validated['contacts'][0];

        $primaryEmail = $primaryContact['email'] ?? null;
        $primaryPhone = $primaryContact['phone'] ?? null;

        $emailExists = $primaryEmail && $this->duplicateService->parentEmailExists($primaryEmail);
        $phoneExists = ! $primaryEmail && $primaryPhone && $this->duplicateService->parentPhoneExists($primaryPhone);

        $expiryDays = SystemConfiguration::getValue('pre_registration_expiry_days', 30);

        $preRegistration = PreRegistration::create([
            'branch_id' => $branchId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'student_number' => $validated['student_number'] ?? null,
            'grade_level' => $validated['grade_level'],
            'section' => $validated['section'] ?? null,
            'birthday' => $birthday,
            'enrollment_type' => $validated['enrollment_type'],
            'allergies' => isset($validated['allergies']) ? strip_tags($validated['allergies']) : null,
            'notes' => isset($validated['notes']) ? strip_tags($validated['notes']) : null,
            'subscription_start_month' => $validated['subscription_start_month'] ?? null,
            'subscription_start_year' => $validated['subscription_start_year'] ?? null,
            'subscription_end_month' => $validated['subscription_end_month'] ?? null,
            'subscription_end_year' => $validated['subscription_end_year'] ?? null,
            'signatory_name' => $validated['signatory_name'],
            'acknowledged_at' => now(),
            'status' => PreRegistrationStatus::Pending,
            'submitter_ip' => $request->ip(),
            'expires_at' => now()->addDays($expiryDays),
            'duplicate_check_passed_at' => now(),
            'parent_email_exists' => $emailExists,
            'parent_phone_exists' => $phoneExists,
        ]);

        $preRegistration->contacts()->createMany($validated['contacts']);

        return response()->json([
            'data' => [
                'id' => $preRegistration->id,
                'status' => $preRegistration->status->value,
            ],
            'warnings' => [
                'parent_email_exists' => $emailExists,
                'parent_phone_exists' => $phoneExists,
            ],
        ], 201);
    }
}
