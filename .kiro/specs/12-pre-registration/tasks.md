# Spec 12 — Tasks

## Task 1: Database, Models & System Config

- [x] 1.1 Create migration for `pre_registrations` table (all fields per design.md including `status` enum, audit columns, reCAPTCHA fields, `expires_at`) — done
- [x] 1.2 Create migration for `pre_registration_contacts` table with FK cascade delete to `pre_registrations` — done
- [x] 1.3 Add `pre_registration_expiry_days` (integer, 30, label: "Pre-Registration Expiry Days") to `SystemConfigurationSeeder` — done
- [x] 1.4 Create `App\Models\PreRegistration` with `HasBranch` trait, `$fillable`, `casts` for `status` enum, and relationships:
  - `contacts()` hasMany `PreRegistrationContact`
  - `branch()` belongsTo `Branch`
  - `approvedBy()` belongsTo `User`
  - `rejectedBy()` belongsTo `User`
- [x] 1.5 Create `App\Models\PreRegistrationContact` with `$fillable` and `belongsTo PreRegistration` — done
- [x] 1.6 Create `App\Enums\PreRegistrationStatus` string-backed enum: `Pending`, `Approved`, `Rejected`, `Expired` — done
- [x] 1.7 Run Pint formatter; run existing test suite — must remain green — 479/479 pass

---

## Task 2: reCAPTCHA Configuration

- [x] 2.1 Add `recaptcha` entry to `config/services.php` — done
- [x] 2.2 Add `RECAPTCHA_SECRET_KEY` and `RECAPTCHA_THRESHOLD` to `.env` and `.env.example` — done
- [x] 2.3 Run Pint formatter — clean

---

## Task 3: Public API Endpoints

- [x] 3.1 Create `App\Http\Controllers\Public\BranchController` — done
- [x] 3.2 Create `App\Http\Controllers\Public\PreRegistrationController` — done (honeypot + reCAPTCHA + validate + create + mail + notify)
- [x] 3.3 Register public routes in `routes/api.php` under `Route::prefix('v1/public')` with `throttle:3,60` — done
- [x] 3.4 Run Pint formatter — clean

---

## Task 3.5: Extract EnrollmentService (prerequisite for Task 4)

- [x] 3.5.1 Create `App\Services\EnrollmentService` — extracted from `EnrollmentController::store()`, used by both controllers — done
- [x] 3.5.2 Run Pint formatter; run `EnrollmentTest` to confirm zero regressions — 15/15 pass

---

## Task 4: Kitchen API Endpoints

- [x] 4.1 Create `App\Http\Controllers\Kitchen\PreRegistrationController` (index, show, update, approve, reject, reactivate) — done
- [x] 4.2 Register routes in `routes/kitchen-api.php` — done
- [x] 4.3 Run Pint formatter — clean

---

## Task 5: Mail Classes

- [x] 5.1 Create `App\Mail\PreRegistrationReceivedMail` (queued, Markdown) — done
- [x] 5.2 Create `App\Mail\PreRegistrationApprovedMail` (queued, Markdown) — done
- [x] 5.3 Create `App\Mail\PreRegistrationRejectedMail` (queued, Markdown) — done
- [x] 5.4 Create Blade templates in `resources/views/emails/pre-registration/` — done (received, approved, rejected)
- [x] 5.5 Run Pint formatter — clean

---

## Task 6: Staff Notification & Expiry Command

- [x] 6.1 Create `App\Notifications\PreRegistrationNotification` (ShouldQueue + ShouldBroadcast) — done
- [x] 6.2 Create `App\Console\Commands\ExpirePreRegistrations` — done
- [x] 6.3 Register command in `routes/console.php` — done (`dailyAt('00:00')`)
- [x] 6.4 Run Pint formatter — clean

---

## Task 7: Backend Tests

- [x] 7.1 Create `tests/Feature/Public/PreRegistrationTest.php` — 7 tests all pass
- [x] 7.2 Create `tests/Feature/Kitchen/PreRegistrationTest.php` — 18 tests all pass
- [x] 7.3 Run all new tests; run full suite — 479/479 green

---

## Task 8: Frontend Portal — Public Pre-Registration Form

- [x] 8.1 `npm install react-google-recaptcha-v3` in `~/sunbites-portal`; added `NEXT_PUBLIC_RECAPTCHA_SITE_KEY` to `.env.example` — done
- [x] 8.2 `types/pre-registration.ts` — `PreRegistrationPayload`, `PreRegistrationContact`, `Branch` — done
- [x] 8.3 `lib/api/pre-registration.ts` — `preRegistrationApi.submit()`, `preRegistrationApi.branches()` — done
- [x] 8.4 Created `app/(public)/layout.tsx` (minimal, no auth) and `app/(public)/pre-register/layout.tsx` (GoogleReCaptchaProvider) — done
- [x] 8.5 `app/(public)/pre-register/page.tsx` — Server Component shell with metadata — done
- [x] 8.6 `components/pre-registration/pre-registration-form.tsx` — full Client Component with all sections, honeypot, reCAPTCHA, Zod validation — done
- [x] 8.7 Success state built into form component with "Submit another" reset — done
- [x] 8.8 Pre-register link added to portal login page — done; lint: 0 errors

---

## Task 9: Frontend POS — Pre-Registrations Pages

- [x] 9.1 `types/pre-registration.ts` in `~/sunbites-pos` — all types done
- [x] 9.2 `lib/api/pre-registrations.ts` — list, show, update, approve, reject, reactivate — done
- [x] 9.3 `app/(kitchen)/pre-registrations/page.tsx` — status tab UI, table, row click navigation — done
- [x] 9.4 `app/(kitchen)/pre-registrations/loading.tsx` — skeleton — done
- [x] 9.5 `app/(kitchen)/pre-registrations/[id]/page.tsx` — duplicate warning, editable form, fraud info, approve/reject/reactivate actions, reject dialog — done
- [x] 9.6 `app/(kitchen)/pre-registrations/[id]/loading.tsx` — skeleton — done
- [x] 9.7 "Pre-Registrations" nav item added to kitchen-layout between Enrollment and Students; ClipboardCheck icon; admin|manager|supervisor only; pending count badge — done; lint: 0 errors
