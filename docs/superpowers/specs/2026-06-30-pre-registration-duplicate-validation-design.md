# Pre-Registration Duplicate Validation Design

**Date:** 2026-06-30
**Branch:** Portal pre-registration flow
**Scope:** Laravel API (`~/sunbites-api`) + Parent portal (`~/sunbites-portal`)

---

## Problem

The pre-registration form on the parent portal has no duplicate detection. A parent can submit multiple pre-registrations for the same student, and the existing POS approval duplicate check only guards on `student_number` — which is nullable and therefore skipped when absent. This leads to duplicate student records.

---

## Goals

- Prevent duplicate pre-registrations for students already enrolled or pending review
- Detect when a parent may already have a portal account and inform them accordingly
- Harden the POS approval flow so name + birthday is the primary duplicate guard (not `student_number`)

---

## Out of Scope

- Fuzzy name matching (e.g., Levenshtein distance)
- Adding children from within a logged-in parent's portal account (handled via pre-registration only)
- Cross-branch duplicate detection (scoped to the active branch)

---

## Uniqueness Criteria

### Student Identity

A student is considered a duplicate when **all three** match within the same branch:

| Field | Comparison |
|---|---|
| `first_name` | Case-insensitive, whitespace-trimmed |
| `last_name` | Case-insensitive, whitespace-trimmed |
| `birthday` | Exact date match |

`student_number` is **not** used as a uniqueness criterion because it is nullable. It remains a supplementary check at POS approval time only when provided.

### What to Check Against

1. `students` table — active enrolled students (`deleted_at IS NULL`)
2. `pre_registrations` table — records with status `pending` or `approved`

Rejected and expired pre-registrations are excluded — they should not block new submissions.

---

## Parent Identity

Pre-registration is always anonymous. Parent duplicate checks are informational only — they never block form submission. They help staff identify whether the parent already has a portal account so linking is handled correctly at approval.

| Priority | Field | Check | Result |
|---|---|---|---|
| 1st | `email` | Match `parents.email` | Soft info message to parent; flag stored on record |
| 2nd | `phone` (when no email) | Match `parents.phone` | Soft info message to parent; flag stored on record |
| Neither | — | Skip | No action |

When a parent with an existing account submits a pre-registration (e.g., adding a second child), the soft info message reads: *"This email is linked to an existing parent account. Your child will be linked to it upon approval."* Submission still proceeds.

---

## Architecture

### New Endpoint 1 — Real-Time Check

```
POST /api/v1/portal/pre-registrations/check
```

- **Auth:** Public (no `auth:parents` guard)
- **Rate limit:** 10 requests per minute per IP
- **Controller:** `App\Http\Controllers\Portal\PreRegistrationCheckController`
- **Purpose:** Called by the portal after the birthday field is filled (debounced 500ms); returns boolean flags only — no student details

**Request:**
```json
{
  "branch_id": 1,
  "first_name": "Juan",
  "last_name": "dela Cruz",
  "birthday": "2015-03-15",
  "email": "parent@example.com",
  "phone": "09171234567"
}
```

`email` and `phone` are optional. `branch_id`, `first_name`, `last_name`, `birthday` are required.

**Response:**
```json
{
  "student": {
    "is_duplicate": true,
    "status": "enrolled"
  },
  "parent": {
    "email_exists": false,
    "phone_exists": false
  }
}
```

`student.status` values: `"enrolled"` | `"pending"` | `null`

**Privacy:** Response returns only boolean flags. No student name, ID, or personal details are exposed.

---

### New Endpoint 2 — Pre-Registration Submit

```
POST /api/v1/portal/pre-registrations
```

- **Auth:** Public (no `auth:parents` guard)
- **Rate limit:** 5 requests per 10 minutes per IP
- **Controller:** `App\Http\Controllers\Portal\PreRegistrationController@store`
- **Purpose:** Accepts the full pre-registration form, validates, creates the record

**Validation sequence:**

```
1. Standard field validation (required fields, formats, date checks)
2. Student duplicate check → HARD BLOCK (422) if found
3. Parent duplicate check → soft warning only, never blocks
4. Create pre_registration record with flags
5. Return 201 with any parent warnings
```

**Student duplicate — 422 response:**
```json
{
  "message": "A student with these details is already enrolled.",
  "errors": {
    "student": ["A student named Juan dela Cruz (born 2015-03-15) is already enrolled at this branch."]
  }
}
```

For `pending` status: *"A pre-registration for this student is already pending review."*

**Successful response with parent warning — 201:**
```json
{
  "data": { "id": 42, "status": "pending" },
  "warnings": {
    "parent_email_exists": true,
    "parent_phone_exists": false
  }
}
```

**Routing — public route group in `portal-api.php`:**
```php
Route::middleware(['throttle:10,1'])->group(function () {
    Route::post('pre-registrations/check', [PreRegistrationCheckController::class, 'check']);
});

Route::middleware(['throttle:5,10'])->group(function () {
    Route::post('pre-registrations', [PreRegistrationController::class, 'store']);
});
```

---

### Pre-Registration Table Changes

Three new columns on `pre_registrations`:

| Column | Type | Default | Purpose |
|---|---|---|---|
| `duplicate_check_passed_at` | `timestamp, nullable` | `null` | Audit trail — confirms server-side check ran at submit time |
| `parent_email_exists` | `boolean` | `false` | Flag: submitted email matched an existing parent account |
| `parent_phone_exists` | `boolean` | `false` | Flag: submitted phone matched an existing parent account (no email case) |

The `parent_email_exists` and `parent_phone_exists` flags are surfaced in the POS approval view so staff can see a notice: *"This parent may already have a portal account."*

---

### Updated POS Approval Flow

**Current behavior:** `PreRegistrationController::approve()` checks `student_number` only, and skips entirely when `student_number` is null.

**Updated behavior:** Name + birthday becomes the **primary** duplicate guard. Student number is supplementary.

```
Staff clicks Approve
    ↓
Lock pre-registration (existing behavior)
    ↓
[PRIMARY] Check first_name + last_name + birthday against students table
    → abort 422 if enrolled student found (case-insensitive, trimmed)
    ↓
[SUPPLEMENTARY] If student_number is not null → check against students table
    → abort 422 if student_number match found within branch
    ↓
Proceed with EnrollmentService::enroll()
    ↓
ParentProvisioningService::provision()
    → firstOrCreate by email → links student to existing or new parent account
```

**Why both checks at approval:** The name+birthday check catches cases where a student was enrolled between pre-registration submission and approval. The student_number check catches cases where staff assigns a number that conflicts with an existing record.

---

## Portal Frontend Behavior

| Trigger | Action |
|---|---|
| All three fields filled (`first_name`, `last_name`, `birthday`) | Debounced 500ms → call `/check` endpoint |
| Name or birthday field changes after initial check | Re-trigger check |
| Email or phone filled/changed after check | Re-trigger check with current name+birthday values |
| `student.is_duplicate: true` | Show inline block message below birthday field; disable submit button |
| `parent.email_exists: true` | Show inline info message below email field; allow submit |
| `parent.phone_exists: true` | Show inline info message below phone field; allow submit |

---

## Tests

### Real-Time Check Endpoint

```
✓ returns student.is_duplicate: true, status: 'enrolled' when name + birthday matches active student
✓ returns student.is_duplicate: true, status: 'pending' when name + birthday matches pending pre-registration
✓ returns student.is_duplicate: false when no student or pre-registration matches
✓ returns parent.email_exists: true when email matches existing parent account
✓ returns parent.phone_exists: true when phone matches and no email provided
✓ returns parent.email_exists: false when email has no match
✓ does NOT return student name, ID, or any identifying details in response
✓ returns 422 when first_name, last_name, birthday, or branch_id is missing
✓ ignores soft-deleted students
✓ ignores rejected and expired pre-registrations
```

### Portal Pre-Registration Submit

```
✓ returns 422 when student name + birthday matches enrolled student
✓ returns 422 when student name + birthday matches pending pre-registration
✓ returns 201 with warnings.parent_email_exists: true when email matches existing parent
✓ returns 201 with warnings.parent_phone_exists: true when phone matches and no email provided
✓ returns 201 and creates record when no duplicate exists
✓ sets duplicate_check_passed_at on the created record
✓ sets parent_email_exists flag correctly on the record
✓ sets parent_phone_exists flag correctly when email absent
✓ name matching is case-insensitive ('juan' matches 'Juan')
✓ name matching trims leading and trailing whitespace
✓ does not block when a rejected pre-registration has matching name + birthday
```

### POS Approval Flow

```
✓ blocks approval when name + birthday matches enrolled student and student_number is null
✓ blocks approval when name + birthday matches enrolled student and student_number is set
✓ blocks approval when student_number matches enrolled student (student_number not null)
✓ does NOT block when student_number is null and no name+birthday match found
✓ proceeds with enrollment when no duplicate found
✓ links new student to existing parent account via email at approval
✓ creates new parent account when no matching email found
```

---

## Files to Create / Modify

### New Files (API)
- `app/Http/Controllers/Portal/PreRegistrationCheckController.php`
- `app/Http/Controllers/Portal/PreRegistrationController.php` (portal-facing, separate from Kitchen)
- `database/migrations/YYYY_MM_DD_add_duplicate_fields_to_pre_registrations_table.php`
- `tests/Feature/Portal/PreRegistrationCheckTest.php`
- `tests/Feature/Portal/PreRegistrationStoreTest.php`

### Modified Files (API)
- `routes/portal-api.php` — add public route group for check + store endpoints
- `app/Http/Controllers/Kitchen/PreRegistrationController.php` — update `approve()` duplicate check logic

### New Test Files (API)
- `tests/Feature/Kitchen/PreRegistrationApprovalDuplicateTest.php`

### Frontend (Portal)
- Pre-registration form page — add debounced check call, inline warning UI, submit disable logic
