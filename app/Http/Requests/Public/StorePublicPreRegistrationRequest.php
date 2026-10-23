<?php

namespace App\Http\Requests\Public;

use App\Enums\SchoolMonth;
use App\Services\RecaptchaVerifier;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class StorePublicPreRegistrationRequest extends FormRequest
{
    private ?float $recaptchaScore = null;

    /**
     * The public form needs no login; the honeypot and reCAPTCHA keep bots out.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The honeypot runs first, then reCAPTCHA. Both answer before the form is validated, as
     * they did in the controller.
     */
    protected function prepareForValidation(): void
    {
        // Honeypot — bots fill the hidden website field; return 201 without creating a record
        if ($this->filled('website')) {
            throw new HttpResponseException(response()->json(['message' => 'Pre-registration received.'], 201));
        }

        $token = $this->input('recaptcha_token');

        try {
            $captchaData = $this->container->make(RecaptchaVerifier::class)->verify(is_string($token) ? $token : null, $this->ip());
        } catch (ConnectionException) {
            Log::warning('reCAPTCHA verification service unavailable', ['ip' => $this->ip()]);

            throw new HttpResponseException(response()->json(['message' => 'Verification service temporarily unavailable. Please try again shortly.'], 503));
        }

        $captchaThreshold = (float) config('services.recaptcha.threshold', 0.5);

        if (! ($captchaData['success'] ?? false) || ($captchaData['score'] ?? 0) < $captchaThreshold) {
            throw new HttpResponseException(response()->json(['message' => 'Submission could not be verified. Please try again.'], 422));
        }

        $this->recaptchaScore = $captchaData['score'] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'student_number' => ['nullable', 'string', 'max:50'],
            'grade_level' => ['required', 'string', Rule::in(config('sunbites.grade_levels'))],
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
        ];
    }

    /**
     * A subscription that ends before it starts is refused with an errors-only body (no
     * `message` key), as it was before the move.
     */
    protected function passedValidation(): void
    {
        $validated = $this->validated();

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
                throw new HttpResponseException(response()->json(['errors' => ['subscription_end_month' => ['End month must be after start month.']]], 422));
            }
        }
    }

    /**
     * The score Google gave this submission, stored with the pre-registration.
     */
    public function recaptchaScore(): ?float
    {
        return $this->recaptchaScore;
    }
}
