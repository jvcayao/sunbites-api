<?php

namespace App\Http\Controllers\Public;

use App\Enums\PreRegistrationStatus;
use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Mail\PreRegistrationReceivedMail;
use App\Models\Branch;
use App\Models\PreRegistration;
use App\Models\SystemConfiguration;
use App\Notifications\PreRegistrationNotification;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class PreRegistrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // Honeypot — bots fill the hidden website field; return 201 without creating a record
        if ($request->filled('website')) {
            return response()->json(['message' => 'Pre-registration received.'], 201);
        }

        try {
            $captchaData = Http::timeout(5)->asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => config('services.recaptcha.secret'),
                'response' => $request->input('recaptcha_token'),
                'remoteip' => $request->ip(),
            ])->json();
        } catch (ConnectionException) {
            Log::warning('reCAPTCHA verification service unavailable', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Verification service temporarily unavailable. Please try again shortly.'], 503);
        }

        $captchaThreshold = (float) config('services.recaptcha.threshold', 0.5);

        if (! ($captchaData['success'] ?? false) || ($captchaData['score'] ?? 0) < $captchaThreshold) {
            return response()->json(['message' => 'Submission could not be verified. Please try again.'], 422);
        }

        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'student_number' => ['nullable', 'string', 'max:50'],
            'grade_level' => ['required', 'string', 'in:'.implode(',', config('sunbites.grade_levels'))],
            'section' => ['nullable', 'string', 'max:100'],
            'birthday' => ['required', 'date', 'before:today'],
            'enrollment_type' => ['required', 'in:subscription,non_subscription'],
            'allergies' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'subscription_start_month' => ['required_if:enrollment_type,subscription', Rule::enum(SchoolMonth::class)],
            'subscription_start_year' => ['required_if:enrollment_type,subscription', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'subscription_end_month' => ['required_if:enrollment_type,subscription', Rule::enum(SchoolMonth::class)],
            'subscription_end_year' => ['required_if:enrollment_type,subscription', 'integer', 'digits:4', 'min:2020', 'max:2099'],
            'signatory_name' => ['required', 'string', 'max:255'],
            'acknowledged_at' => ['required', 'date', 'before_or_equal:now'],
            'contacts' => ['required', 'array', 'min:1', 'max:3'],
            'contacts.*.full_name' => ['required', 'string', 'max:150'],
            'contacts.*.relationship' => ['required', 'string', 'max:100'],
            'contacts.*.phone' => ['required', 'string', 'max:30'],
            'contacts.*.address' => ['required', 'string', 'max:255'],
            'contacts.*.email' => ['nullable', 'email', 'max:150'],
            'contacts.*.is_primary' => ['boolean'],
        ]);

        if ($validated['enrollment_type'] === 'subscription') {
            $start = Carbon::createFromDate(
                $validated['subscription_start_year'],
                SchoolMonth::from($validated['subscription_start_month'])->toMonthNumber(),
                1
            );
            $end = Carbon::createFromDate(
                $validated['subscription_end_year'],
                SchoolMonth::from($validated['subscription_end_month'])->toMonthNumber(),
                1
            );

            if ($end->lt($start)) {
                return response()->json(['errors' => ['subscription_end_month' => ['End month must be after start month.']]], 422);
            }
        }

        $validated['allergies'] = isset($validated['allergies']) ? strip_tags($validated['allergies']) : null;
        $validated['notes'] = isset($validated['notes']) ? strip_tags($validated['notes']) : null;

        $expiryDays = SystemConfiguration::getValue('pre_registration_expiry_days', 30);

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
            'recaptcha_score' => $captchaData['score'] ?? null,
            'submitter_ip' => $request->ip(),
            'expires_at' => now()->addDays($expiryDays),
        ]);

        $preRegistration->contacts()->createMany($validated['contacts']);
        $preRegistration->load('contacts');

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
