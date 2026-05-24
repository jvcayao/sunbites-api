<?php

namespace App\Http\Controllers\Kitchen;

use App\Http\Controllers\Controller;
use App\Mail\ParentWelcomeMail;
use App\Models\ParentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

class ParentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ParentUser::with('students:id,first_name,last_name,student_number,branch_id')
            ->orderBy('last_name')
            ->orderBy('first_name');

        if (! empty($validated['search'])) {
            $search = '%'.mb_strtolower($validated['search']).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(first_name) like ?', [$search])
                ->orWhereRaw('lower(last_name) like ?', [$search])
                ->orWhereRaw('lower(email) like ?', [$search])
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
        $parent->load(['students:id,first_name,last_name,student_number,grade_level,branch_id', 'students.branch:id,name']);

        return response()->json([
            'id' => $parent->id,
            'full_name' => $parent->full_name,
            'email' => $parent->email,
            'phone' => $parent->phone,
            'address' => $parent->address,
            'profile_photo_path' => $parent->profile_photo_path,
            'is_activated' => $parent->isActivated(),
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
        $token = Password::broker('parents')->createToken($parent);
        Mail::to($parent->email)->queue(new ParentWelcomeMail($parent, $token));

        return response()->json(['message' => 'Activation email sent.']);
    }
}
