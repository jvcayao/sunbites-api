<?php

namespace App\Http\Controllers\Kitchen;

use App\Actions\Parents\DisableParentAction;
use App\Actions\Parents\EnableParentAction;
use App\Actions\Parents\RestoreParentAction;
use App\Actions\Parents\SoftDeleteParentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ListParentsRequest;
use App\Mail\ParentWelcomeMail;
use App\Models\ParentUser;
use App\Models\StudentContact;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class ParentController extends Controller
{
    public function __construct(
        private readonly DisableParentAction $disableParent,
        private readonly EnableParentAction $enableParent,
        private readonly SoftDeleteParentAction $softDeleteParent,
        private readonly RestoreParentAction $restoreParent,
    ) {}

    public function index(ListParentsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $query = ParentUser::with('students:id,first_name,last_name,student_number,branch_id')
            ->orderBy('last_name')
            ->orderBy('first_name');

        match ($validated['status'] ?? null) {
            'active' => $query->whereNotNull('email_verified_at')->whereNull('disabled_at'),
            'pending' => $query->whereNull('email_verified_at')->whereNull('disabled_at'),
            'disabled' => $query->whereNotNull('disabled_at'),
            'deleted' => $query->onlyTrashed(),
            default => null,
        };

        $query->whereHas('students', fn ($q) => $q->where('students.branch_id', app('active_branch')->id)
        );

        if (! empty($validated['search'])) {
            $search = mb_strtolower($validated['search']);
            $query->where(fn ($q) => $q->whereContainsLiteral('lower(first_name)', $search)
                ->whereContainsLiteral('lower(last_name)', $search, 'or')
                ->whereContainsLiteral('lower(email)', $search, 'or')
            );
        }

        $parents = $query->paginate($validated['per_page'] ?? 25);

        return response()->json([
            'data' => collect($parents->items())->map(fn ($parent) => [
                'id' => $parent->id,
                'full_name' => $parent->full_name,
                'email' => $parent->email,
                'phone' => $parent->phone,
                'is_activated' => $parent->isActivated(),
                'is_disabled' => $parent->isDisabled(),
                'deleted_at' => $parent->deleted_at,
                'students_count' => $parent->students->count(),
                'students' => $parent->students->map(fn ($s) => [
                    'id' => $s->id,
                    'student_number' => $s->student_number,
                    'full_name' => $s->full_name,
                ]),
            ]),
            'meta' => $this->paginationMeta($parents),
        ]);
    }

    public function show(ParentUser $parent): JsonResponse
    {
        $this->ensureVisibleInActiveBranch($parent);

        $parent->load(['students:id,first_name,last_name,student_number,grade_level,branch_id', 'students.branch:id,name']);

        // Phone and address come from the student contact record matching this parent's email,
        // falling back to whatever the parent filled in on their portal profile.
        $contact = StudentContact::where('email', $parent->email)->latest()->first();

        return response()->json([
            'id' => $parent->id,
            'full_name' => $parent->full_name,
            'email' => $parent->email,
            'phone' => $parent->phone ?? $contact?->phone,
            'address' => $parent->address ?? $contact?->address,
            'profile_photo_url' => $parent->profile_photo_url,
            'is_activated' => $parent->isActivated(),
            'is_disabled' => $parent->isDisabled(),
            'deleted_at' => $parent->deleted_at,
            'created_at' => $parent->created_at,
            'students' => $parent->students->map(fn ($s) => [
                'id' => $s->id,
                'student_number' => $s->student_number,
                'full_name' => $s->full_name,
                'grade_level' => $s->grade_level,
                'branch_name' => $s->branch?->name,
                'wallet_alert_threshold' => (float) $s->pivot->wallet_alert_threshold,
                'linked_at' => $s->pivot->linked_at,
            ]),
        ]);
    }

    public function resendActivation(ParentUser $parent): JsonResponse
    {
        $this->ensureVisibleInActiveBranch($parent);

        $token = Password::broker('parents')->createToken($parent);
        Mail::to($parent->email)->queue(new ParentWelcomeMail($parent, $token));

        return response()->json(['message' => 'Activation email sent.']);
    }

    public function disable(ParentUser $parent): JsonResponse
    {
        $this->ensureVisibleInActiveBranch($parent);

        $this->disableParent->execute($parent);

        return response()->json(['message' => 'Parent access disabled.']);
    }

    public function enable(ParentUser $parent): JsonResponse
    {
        $this->ensureVisibleInActiveBranch($parent);

        $this->enableParent->execute($parent);

        return response()->json(['message' => 'Parent access enabled. Activation email queued.']);
    }

    public function destroy(ParentUser $parent): JsonResponse
    {
        $this->ensureVisibleInActiveBranch($parent);

        $this->softDeleteParent->execute($parent);

        return response()->json(['message' => 'Parent account deleted.']);
    }

    public function restore(ParentUser $parent): JsonResponse
    {
        $this->ensureVisibleInActiveBranch($parent);

        if (! $parent->trashed()) {
            abort(404);
        }

        $this->restoreParent->execute($parent);

        return response()->json(['message' => 'Parent account restored. Activation email queued.']);
    }

    /**
     * A parent is visible only through a child (soft-deleted included) in the active branch.
     *
     * @throws ModelNotFoundException
     */
    private function ensureVisibleInActiveBranch(ParentUser $parent): void
    {
        $visible = $parent->students()
            ->withTrashed()
            ->where('students.branch_id', app('active_branch')->id)
            ->exists();

        if (! $visible) {
            throw (new ModelNotFoundException)->setModel(ParentUser::class);
        }
    }
}
