<?php

namespace App\Http\Controllers\Kitchen;

use App\Actions\PreRegistrations\ApprovePreRegistrationAction;
use App\Enums\PreRegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ListPreRegistrationsRequest;
use App\Http\Requests\Kitchen\RejectPreRegistrationRequest;
use App\Http\Requests\Kitchen\UpdatePreRegistrationRequest;
use App\Mail\PreRegistrationRejectedMail;
use App\Models\PreRegistration;
use App\Models\Student;
use App\Models\SystemConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PreRegistrationController extends Controller
{
    public function index(ListPreRegistrationsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $statusFilter = isset($validated['status'])
            ? PreRegistrationStatus::from($validated['status'])
            : PreRegistrationStatus::Pending;

        $query = PreRegistration::with([
            'contacts' => fn ($q) => $q->where('is_primary', true),
        ])->where('status', $statusFilter);

        if (! empty($validated['enrollment_type'])) {
            $query->where('enrollment_type', $validated['enrollment_type']);
        }

        if (! empty($validated['search'])) {
            $like = '%'.mb_strtolower($validated['search']).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(first_name) like ?', [$like])
                ->orWhereRaw('lower(last_name) like ?', [$like])
            );
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        $preRegistrations = $query->latest()->paginate($validated['per_page'] ?? 15);

        $items = $preRegistrations->getCollection()->map(fn (PreRegistration $preReg) => [
            'id' => $preReg->id,
            'student_name' => $preReg->full_name,
            'enrollment_type' => $preReg->enrollment_type,
            'status' => $preReg->status->value,
            'submitted_at' => $preReg->created_at,
            'expires_at' => $preReg->expires_at,
            'contact_name' => $preReg->contacts->first()?->full_name,
        ]);

        return response()->json([
            'data' => $items,
            'meta' => $this->paginationMeta($preRegistrations),
        ]);
    }

    public function show(PreRegistration $preRegistration): JsonResponse
    {
        $preRegistration->load('contacts', 'approvedBy', 'rejectedBy');

        $duplicateWarning = false;
        $existingStudentName = null;

        if ($preRegistration->student_number) {
            $existing = Student::withoutGlobalScopes()
                ->where('branch_id', $preRegistration->branch_id)
                ->where('student_number', $preRegistration->student_number)
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                $duplicateWarning = true;
                $existingStudentName = $existing->full_name;
            }
        }

        $data = $preRegistration->toArray();
        $data['approved_by'] = $preRegistration->approvedBy?->full_name;
        $data['rejected_by'] = $preRegistration->rejectedBy?->full_name;

        return response()->json([
            'data' => array_merge($data, [
                'duplicate_warning' => $duplicateWarning,
                'existing_student_name' => $existingStudentName,
            ]),
        ]);
    }

    public function update(UpdatePreRegistrationRequest $request, PreRegistration $preRegistration): JsonResponse
    {
        $validated = $request->validated();

        if (isset($validated['allergies'])) {
            $validated['allergies'] = strip_tags($validated['allergies']);
        }

        if (isset($validated['notes'])) {
            $validated['notes'] = strip_tags($validated['notes']);
        }

        DB::transaction(function () use ($preRegistration, $validated): void {
            $preRegistration->update(Arr::except($validated, ['contacts']));

            if (isset($validated['contacts'])) {
                $preRegistration->contacts()->delete();
                $preRegistration->contacts()->createMany($validated['contacts']);
            }
        });

        return response()->json(['message' => 'Pre-registration updated.', 'data' => $preRegistration->fresh(['contacts'])]);
    }

    public function approve(Request $request, PreRegistration $preRegistration, ApprovePreRegistrationAction $approve): JsonResponse
    {
        if ($preRegistration->status !== PreRegistrationStatus::Pending) {
            return response()->json(['message' => 'Only pending pre-registrations can be approved.'], 422);
        }

        $student = $approve->execute($preRegistration, $request->user());

        return response()->json([
            'message' => 'Pre-registration approved. Student enrolled successfully.',
            'data' => [
                'id' => $student->id,
                'student_number' => $student->student_number,
                'full_name' => $student->full_name,
                'qr_code' => $student->qr_code,
            ],
        ]);
    }

    public function reject(RejectPreRegistrationRequest $request, PreRegistration $preRegistration): JsonResponse
    {
        $validated = $request->validated();

        $preRegistration->update([
            'status' => PreRegistrationStatus::Rejected,
            'rejected_by' => $request->user()->id,
            'rejection_reason' => $validated['rejection_reason'],
            'processed_at' => now(),
        ]);

        $preRegistration->load('contacts');

        $primaryEmail = $preRegistration->contacts->firstWhere('is_primary', true)?->email
            ?? $preRegistration->contacts->first()?->email;

        if ($primaryEmail) {
            Mail::to($primaryEmail)->queue(new PreRegistrationRejectedMail($preRegistration));
        }

        return response()->json(['message' => 'Pre-registration rejected.']);
    }

    public function reactivate(Request $request, PreRegistration $preRegistration): JsonResponse
    {
        if ($preRegistration->status !== PreRegistrationStatus::Expired) {
            return response()->json(['message' => 'Only expired pre-registrations can be reactivated.'], 422);
        }

        $expiryDays = SystemConfiguration::getValue('pre_registration_expiry_days', 30);

        $preRegistration->update([
            'status' => PreRegistrationStatus::Pending,
            'expires_at' => now()->addDays($expiryDays),
        ]);

        activity('pre_registrations')
            ->causedBy($request->user())
            ->performedOn($preRegistration)
            ->log('pre-registration.reactivated');

        return response()->json(['message' => 'Pre-registration reactivated.']);
    }
}
