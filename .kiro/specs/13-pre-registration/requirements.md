# Spec 13 — Pre-Registration

## Introduction

Pre-Registration is a public-facing form on the parent portal domain (`portal.sunbites.com.ph/pre-register`) that allows families to submit a student enrollment request before visiting the school. It is separate from the live `students` and `parents` tables — all data lives in `pre_registrations` and `pre_registration_contacts` until a staff member approves it. On approval, the pre-registration data is converted into a real student enrollment using the same flow as `EnrollmentController::store()`.

---

## Requirements

### Requirement 1 — Public Pre-Registration Submission

**User Story:** As a parent, I want to pre-register my child online, so that I can prepare enrollment before visiting the canteen.

#### Acceptance Criteria

1. WHEN a visitor submits the pre-registration form with all required fields THEN the system SHALL create a `pre_registrations` record with status `pending` and return 201.
2. WHEN the form is submitted THEN the system SHALL verify the Google reCAPTCHA v3 token server-side; IF the score is below 0.5 or verification fails THEN the system SHALL return 422 with message "Submission could not be verified. Please try again."
3. WHEN the form is submitted THEN the system SHALL send a `PreRegistrationReceivedMail` to the primary contact's email (if provided) confirming receipt and informing them that the canteen will review and contact them.
4. WHEN the form is submitted THEN the system SHALL notify all staff members assigned to the selected branch via the staff notification bell (Reverb broadcast on `staff.{userId}`).
5. WHEN the form is submitted THEN `student_number` SHALL be optional — the system SHALL accept submissions with a blank or absent `student_number`; when provided, it is stored as-is with no uniqueness check at submission time (uniqueness is enforced at approval time).
6. IF `enrollment_type` is `subscription` THEN the system SHALL require `subscription_start_month`, `subscription_start_year`, `subscription_end_month`, `subscription_end_year`; validate that end is not before start.
7. WHEN `enrollment_type` is `non_subscription` THEN the system SHALL not require subscription period fields.
8. WHEN a honeypot field (`website`) is present and non-empty in the request THEN the system SHALL silently return 201 without creating any record (bot trap).
9. WHEN the same IP address submits more than 3 pre-registrations within 60 minutes THEN the system SHALL return 429.
10. WHEN a pre-registration is created THEN `expires_at` SHALL be set to `created_at + pre_registration_expiry_days` (configurable via system config, default 30 days).

### Requirement 2 — Public Branch List

**User Story:** As a visitor filling the pre-registration form, I want to see available branches, so that I can select the correct one.

#### Acceptance Criteria

1. WHEN a visitor requests the public branch list THEN the system SHALL return all active branches with `id` and `name` only — no sensitive data.
2. WHERE this endpoint exists it SHALL require no authentication.

### Requirement 3 — POS Pre-Registration List

**User Story:** As an Admin, Manager, or Supervisor, I want to see all pre-registrations for my branch, so that I can process them.

#### Acceptance Criteria

1. WHEN a staff member requests the pre-registration list THEN the system SHALL return pre-registrations scoped to the active branch, newest first.
2. WHEN returning the list THEN each entry SHALL include: student name, enrollment type, status badge, submission date, expiry date, and primary contact name.
3. WHEN `status` filter is provided THEN the system SHALL return only pre-registrations matching that status.
4. IF no filter is provided THEN the system SHALL default to showing `pending` records only.
5. WHEN a pre-registration's `expires_at` has passed and status is still `pending` THEN a daily scheduled command SHALL change its status to `expired`.
6. IF the authenticated staff member is not assigned to the active branch THEN branch scoping prevents them from seeing those pre-registrations (standard `BranchScope` behavior).

### Requirement 4 — View and Edit Pre-Registration

**User Story:** As an Admin, Manager, or Supervisor, I want to review and correct a pre-registration before approving it, so that the enrollment data is accurate.

#### Acceptance Criteria

1. WHEN a staff member opens a pre-registration THEN the system SHALL return all fields in a fully editable form.
2. WHEN a staff member saves edits to a `pending` pre-registration THEN the system SHALL update the record and return 200.
3. IF the pre-registration status is `approved` or `rejected` THEN the system SHALL return 422 on edit attempts — processed records are immutable.
4. IF a student with the same `student_number` already exists in the active branch THEN the system SHALL include `{ duplicate_warning: true, existing_student_name: '...' }` in the detail response — staff must acknowledge before approving.

### Requirement 5 — Approve Pre-Registration

**User Story:** As an Admin or Manager, I want to approve a pre-registration, so that the student and parent are enrolled in the system.

#### Acceptance Criteria

1. WHEN an Admin or Manager approves a pre-registration THEN the system SHALL:
   - Create a `Student` record from the pre-registration data (same logic as `EnrollmentController::store()`)
   - Create wallet via `bavix/laravel-wallet`
   - If subscription: seed `StudentMonthlyPayment` records for the subscription period
   - Create `StudentContact` records from pre-registration contacts
   - If primary contact has an email: call `ParentProvisioningService::provision()` to create/link the `ParentUser` and send `ParentWelcomeMail`
   - Set pre-registration `status = approved`, `approved_by = auth user id`, `processed_at = now()`
   - Send `PreRegistrationApprovedMail` to primary contact email
   - Return 200 with the created student resource
2. IF `student_number` already exists in the branch THEN the system SHALL return 422 — staff must resolve the duplicate before approving.
3. IF the authenticated staff has role `supervisor` or `cashier` THEN the system SHALL return 403.

### Requirement 6 — Reactivate Expired Pre-Registration

**User Story:** As an Admin, Manager, or Supervisor, I want to reactivate an expired pre-registration, so that families who are still interested can continue through the approval process.

#### Acceptance Criteria

1. WHEN an Admin, Manager, or Supervisor reactivates an `expired` pre-registration THEN the system SHALL set `status = pending` and reset `expires_at` to `now() + pre_registration_expiry_days`.
2. IF the pre-registration status is not `expired` THEN the system SHALL return 422 — only expired records can be reactivated.
3. IF the authenticated staff has role `cashier` THEN the system SHALL return 403.
4. WHEN a pre-registration is reactivated THEN it SHALL be fully editable and eligible for approval or rejection as normal.

### Requirement 7 — Reject Pre-Registration

**User Story:** As an Admin or Manager, I want to reject a pre-registration with a reason, so that the family knows why it was not approved.

#### Acceptance Criteria

1. WHEN an Admin or Manager rejects a pre-registration THEN the system SHALL set `status = rejected`, `rejected_by = auth user id`, `rejection_reason`, `processed_at = now()`.
2. WHEN a pre-registration is rejected THEN the system SHALL send `PreRegistrationRejectedMail` to the primary contact email (if provided) with the rejection reason.
3. WHEN `rejection_reason` is missing THEN the system SHALL return 422.
4. IF the authenticated staff has role `supervisor` or `cashier` THEN the system SHALL return 403.

---

## Cross-Cutting Requirements

- **Public endpoint security**: reCAPTCHA v3 (score ≥ 0.5), IP rate limiting (3/hour), honeypot field.
- **Data isolation**: Pre-registrations are branch-scoped. Staff not assigned to a branch cannot see its pre-registrations.
- **No direct table writes on approval**: Approval must go through `EnrollmentController` business logic — not raw inserts into `students`.
- **Immutability after processing**: Approved and rejected pre-registrations cannot be edited or re-processed.
- **Audit trail**: `approved_by`, `rejected_by`, `processed_at` are always recorded.
- **reCAPTCHA score storage**: Store `recaptcha_score` on the `pre_registrations` record for fraud audit purposes.
- **Supervisor edit, not approve**: Supervisors can edit pending pre-registrations, view all statuses, and reactivate expired records — but cannot approve or reject.

## Out of Scope

- Online payment at pre-registration time
- Pre-registrant tracking their own submission status (no login for pre-registrants)
- Bulk approval
- Duplicate pre-registration detection (same family submitting twice) — `student_number` uniqueness is checked at approval time only, not at submission. `student_number` is optional and may be blank on the public form.
