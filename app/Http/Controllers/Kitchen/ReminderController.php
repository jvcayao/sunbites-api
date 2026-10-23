<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ListEligibleParentsRequest;
use App\Http\Requests\Kitchen\SendRemindersRequest;
use App\Models\ParentUser;
use App\Services\PaymentReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function __construct(private readonly PaymentReminderService $reminders) {}

    public function bellCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->reminders->bellCount(app('active_branch'))]);
    }

    public function eligibleParents(ListEligibleParentsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $branch = app('active_branch');
        $perPage = $validated['per_page'] ?? 25;
        $status = $validated['status'] ?? 'all';

        $enriched = $this->reminders->eligibleParents($branch, $validated);

        $filtered = match ($status) {
            'unsent' => $enriched->filter(fn ($p) => $p['_none_sent']),
            'partial' => $enriched->filter(fn ($p) => ! $p['_none_sent'] && ! $p['_all_sent']),
            'sent' => $enriched->filter(fn ($p) => $p['_all_sent']),
            'overdue' => $enriched->filter(fn ($p) => $p['has_overdue']),
            default => $enriched,
        };

        $response = $filtered->map(function ($p) {
            unset($p['_all_sent'], $p['_none_sent']);

            return $p;
        })->values();

        $page = $request->integer('page', 1);
        $total = $response->count();
        $items = $response->forPage($page, $perPage)->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    public function send(SendRemindersRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json($this->reminders->send(
            app('active_branch'),
            $validated['parent_ids'],
            (bool) ($validated['force'] ?? false),
            $request->user(),
        ));
    }

    public function show(Request $request, ParentUser $parent): JsonResponse
    {
        $branch = app('active_branch');

        $subscriptionStudents = $parent->students()
            ->where('branch_id', $branch->id)
            ->where('student_type', StudentType::Subscription->value)
            ->with('monthlyPayments')
            ->get();

        abort_if($subscriptionStudents->isEmpty(), 403, 'Parent has no subscription students in this branch.');

        return response()->json([
            'id' => $parent->id,
            'full_name' => $parent->full_name,
            'email' => $parent->email,
            'phone' => $parent->phone,
            'is_activated' => $parent->isActivated(),
            'students' => $subscriptionStudents->map(fn ($s) => [
                'id' => $s->id,
                'full_name' => $s->full_name,
                'student_number' => $s->student_number,
                'grade_level' => $s->grade_level,
                'payment_history' => $s->monthlyPayments->map(fn ($p) => [
                    'id' => $p->id,
                    'school_month' => $p->school_month->value,
                    'year' => $p->year,
                    'amount' => (float) $p->amount,
                    'status' => $p->status,
                    'paid_at' => $p->recorded_at?->toDateTimeString(),
                ]),
            ]),
        ]);
    }
}
