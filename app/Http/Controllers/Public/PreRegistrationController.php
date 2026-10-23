<?php

namespace App\Http\Controllers\Public;

use App\Enums\PreRegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StorePublicPreRegistrationRequest;
use App\Mail\PreRegistrationReceivedMail;
use App\Models\Branch;
use App\Models\PreRegistration;
use App\Models\SystemConfiguration;
use App\Notifications\PreRegistrationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PreRegistrationController extends Controller
{
    public function store(StorePublicPreRegistrationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['allergies'] = isset($validated['allergies']) ? strip_tags($validated['allergies']) : null;
        $validated['notes'] = isset($validated['notes']) ? strip_tags($validated['notes']) : null;

        $expiryDays = SystemConfiguration::getValue('pre_registration_expiry_days', 30);

        $preRegistration = DB::transaction(function () use ($validated, $request, $expiryDays): PreRegistration {
            $preRegistration = PreRegistration::create([
                'branch_id' => $validated['branch_id'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'student_number' => $validated['student_number'] ?? null,
                'grade_level' => $validated['grade_level'],
                'section' => $validated['section'] ?? null,
                'birthday' => $validated['birthday'],
                'enrollment_type' => $validated['enrollment_type'],
                'allergies' => $validated['allergies'],
                'notes' => $validated['notes'],
                'subscription_start_month' => $validated['subscription_start_month'] ?? null,
                'subscription_start_year' => $validated['subscription_start_year'] ?? null,
                'subscription_end_month' => $validated['subscription_end_month'] ?? null,
                'subscription_end_year' => $validated['subscription_end_year'] ?? null,
                'signatory_name' => $validated['signatory_name'],
                'acknowledged_at' => $validated['acknowledged_at'],
                'status' => PreRegistrationStatus::Pending,
                'recaptcha_score' => $request->recaptchaScore(),
                'submitter_ip' => $request->ip(),
                'expires_at' => now()->addDays($expiryDays),
            ]);

            $preRegistration->contacts()->createMany($validated['contacts']);
            $preRegistration->load('contacts');

            return $preRegistration;
        });

        $primaryContact = $preRegistration->contacts->firstWhere('is_primary', true)
            ?? $preRegistration->contacts->first();

        if ($primaryContact?->email) {
            Mail::to($primaryContact->email)->queue(new PreRegistrationReceivedMail($preRegistration));
        }

        Branch::with(['users' => fn ($q) => $q->role(['admin', 'manager', 'supervisor'])])
            ->find($validated['branch_id'])
            ->users->each(fn ($staff) => $staff->notify(new PreRegistrationNotification($preRegistration)));

        return response()->json(['message' => 'Pre-registration received.'], 201);
    }
}
