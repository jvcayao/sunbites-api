<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Mail\ParentWelcomeMail;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\StudentContact;
use App\Services\ParentProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class StudentContactController extends Controller
{
    public function __construct(private readonly ParentProvisioningService $provisioningService) {}

    public function index(Student $student): JsonResponse
    {
        $contacts = $student->contacts()->orderBy('is_primary', 'desc')->get();

        $parentEmails = $contacts->pluck('email')->filter()->unique()->values();

        $parentsByEmail = ParentUser::whereIn('email', $parentEmails)
            ->get(['email', 'email_verified_at'])
            ->keyBy('email');

        $data = $contacts->map(function (StudentContact $contact) use ($parentsByEmail) {
            $portalStatus = 'no_email';

            if ($contact->email) {
                $parent = $parentsByEmail->get($contact->email);
                $portalStatus = $parent?->isActivated() ? 'activated' : 'pending_activation';
            }

            return array_merge($contact->toArray(), ['portal_status' => $portalStatus]);
        });

        return response()->json(['data' => $data]);
    }

    public function store(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'relationship' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
            'address' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_primary' => ['boolean'],
        ]);

        if ($student->contacts()->count() >= 3) {
            return response()->json(['message' => 'A student can have at most 3 contacts.'], 422);
        }

        if (! empty($validated['is_primary'])) {
            $student->contacts()->where('is_primary', true)->update(['is_primary' => false]);
        }

        $contact = $student->contacts()->create($validated);

        if (! empty($validated['email'])) {
            $this->provisioningService->provision(
                $validated['email'],
                $validated['full_name'],
                $student->id,
                $request->user()->id,
            );
        }

        return response()->json($contact, 201);
    }

    public function update(Request $request, Student $student, StudentContact $contact): JsonResponse
    {
        $this->assertContactBelongsToStudent($contact, $student);

        $validated = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:150'],
            'relationship' => ['sometimes', 'string', 'max:100'],
            'phone' => ['sometimes', 'string', 'max:30'],
            'address' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        if (! empty($validated['is_primary'])) {
            $student->contacts()->where('id', '!=', $contact->id)->where('is_primary', true)->update(['is_primary' => false]);
        }

        $oldEmail = $contact->email;
        $contact->update($validated);

        if (isset($validated['email']) && $validated['email'] !== $oldEmail) {
            if (! empty($validated['email'])) {
                $this->provisioningService->provision(
                    $validated['email'],
                    $contact->full_name,
                    $student->id,
                    $request->user()->id,
                );
            }

            if ($oldEmail) {
                $this->provisioningService->detachStudent($oldEmail, $student->id);
            }
        }

        return response()->json($contact->fresh());
    }

    public function destroy(Student $student, StudentContact $contact): JsonResponse
    {
        $this->assertContactBelongsToStudent($contact, $student);

        if ($contact->email) {
            $this->provisioningService->detachStudent($contact->email, $student->id);
        }

        $contact->delete();

        return response()->json(null, 204);
    }

    public function resendActivation(Student $student, StudentContact $contact): JsonResponse
    {
        $this->assertContactBelongsToStudent($contact, $student);

        if (! $contact->email) {
            return response()->json(['message' => 'This contact has no email address.'], 422);
        }

        $parent = ParentUser::where('email', $contact->email)->first();

        if (! $parent) {
            return response()->json(['message' => 'No portal account found for this contact.'], 404);
        }

        $token = Password::broker('parents')->createToken($parent);
        Mail::to($parent->email)->queue(new ParentWelcomeMail($parent, $token));

        return response()->json(['message' => 'Activation email sent.']);
    }

    private function assertContactBelongsToStudent(StudentContact $contact, Student $student): void
    {
        if ($contact->student_id !== $student->id) {
            abort(404, 'Contact does not belong to this student.');
        }
    }
}
