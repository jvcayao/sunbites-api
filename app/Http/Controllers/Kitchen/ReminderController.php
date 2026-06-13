<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Models\ParentPaymentReminder;
use App\Models\ParentUser;
use App\Notifications\PaymentReminderNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ReminderController extends Controller
{
    public function bellCount(Request $request): JsonResponse
    {
        $branch = app('active_branch');

        $parents = $this->buildParentsWithUnpaidPayments($branch)->get();

        if ($parents->isEmpty()) {
            return response()->json(['count' => 0]);
        }

        $sentKeys = ParentPaymentReminder::where('branch_id', $branch->id)
            ->whereIn('parent_user_id', $parents->pluck('id'))
            ->get()
            ->groupBy('parent_user_id')
            ->map(fn ($reminders) => $reminders->map(fn ($r) => $r->school_month.'_'.$r->school_year)->flip());

        $count = $parents->filter(function (ParentUser $parent) use ($sentKeys) {
            $periods = $this->extractUnpaidPeriods($parent);
            $parentSentKeys = $sentKeys->get($parent->id, collect());

            return $periods->contains(fn ($p) => ! $parentSentKeys->has($p['school_month'].'_'.$p['year']));
        })->count();

        return response()->json(['count' => $count]);
    }

    public function eligibleParents(Request $request): JsonResponse
    {
        $schoolMonthValues = collect(SchoolMonth::cases())->map->value->toArray();

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:all,unsent,partial,sent,overdue'],
            'school_month' => ['nullable', 'string', Rule::in($schoolMonthValues)],
            'school_year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branch = app('active_branch');
        $perPage = $validated['per_page'] ?? 25;
        $status = $validated['status'] ?? 'all';

        $parents = $this->buildParentsWithUnpaidPayments(
            $branch,
            $validated['search'] ?? null,
            $validated['school_month'] ?? null,
            $validated['school_year'] ?? null,
        )->get();

        $parentIds = $parents->pluck('id');

        $remindersMap = ParentPaymentReminder::where('branch_id', $branch->id)
            ->whereIn('parent_user_id', $parentIds)
            ->get()
            ->groupBy('parent_user_id');

        $now = now();

        $enriched = $parents->map(function (ParentUser $parent) use ($remindersMap, $now) {
            $periods = $this->extractUnpaidPeriods($parent);
            $reminders = $remindersMap->get($parent->id, collect());
            $remindersByKey = $reminders->keyBy(fn ($r) => $r->school_month.'_'.$r->school_year);

            $hasOverdue = $periods->contains(function ($p) use ($now) {
                $monthNum = SchoolMonth::from($p['school_month'])->toMonthNumber();
                $calYear = $monthNum >= 6 ? $p['year'] : $p['year'] + 1;

                return Carbon::create($calYear, $monthNum, 1)->startOfMonth()->lt($now);
            });

            $totalSendCount = $reminders->sum('send_count');
            $allSent = $periods->isNotEmpty() && $periods->every(fn ($p) => $remindersByKey->has($p['school_month'].'_'.$p['year']));
            $noneSent = $totalSendCount === 0;

            $unpaidPeriods = $periods->map(function ($p) use ($parent, $remindersByKey) {
                $key = $p['school_month'].'_'.$p['year'];
                $reminder = $remindersByKey->get($key);

                $students = $parent->students->map(function ($s) use ($p) {
                    $payment = $s->monthlyPayments
                        ->first(fn ($mp) => $mp->school_month->value === $p['school_month'] && $mp->year === $p['year']);

                    return $payment ? [
                        'id' => $s->id,
                        'full_name' => $s->full_name,
                        'student_number' => $s->student_number,
                        'amount' => (float) $payment->amount,
                    ] : null;
                })->filter()->values();

                return [
                    'school_month' => $p['school_month'],
                    'year' => $p['year'],
                    'was_sent' => $reminder !== null,
                    'last_sent_at' => $reminder?->sent_at?->toDateTimeString(),
                    'send_count' => $reminder?->send_count ?? 0,
                    'total_amount' => $students->sum('amount'),
                    'students' => $students->toArray(),
                ];
            })->values();

            return [
                'id' => $parent->id,
                'full_name' => $parent->full_name,
                'email' => $parent->email,
                'total_send_count' => (int) $totalSendCount,
                'has_overdue' => $hasOverdue,
                '_all_sent' => $allSent,
                '_none_sent' => $noneSent,
                'unpaid_periods' => $unpaidPeriods,
            ];
        });

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

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_ids' => ['required', 'array', 'min:1'],
            'parent_ids.*' => ['required', 'integer'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $branch = app('active_branch');
        $force = $validated['force'] ?? false;
        $sentCount = 0;
        $skippedCount = 0;
        $skippedNames = [];

        $parents = ParentUser::with([
            'students' => fn ($q) => $q
                ->where('branch_id', $branch->id)
                ->where('student_type', StudentType::Subscription->value)
                ->with(['monthlyPayments' => fn ($q2) => $q2->where('status', '!=', 'paid')]),
        ])->whereIn('id', $validated['parent_ids'])->get();

        foreach ($parents as $parent) {
            $allPeriods = $this->extractUnpaidPeriods($parent);

            if ($allPeriods->isEmpty()) {
                $skippedCount++;
                $skippedNames[] = $parent->full_name;

                continue;
            }

            $existingReminders = ParentPaymentReminder::where('parent_user_id', $parent->id)
                ->where('branch_id', $branch->id)
                ->get()
                ->keyBy(fn ($r) => $r->school_month.'_'.$r->school_year);

            $periodsToNotify = $force
                ? $allPeriods
                : $allPeriods->filter(fn ($p) => ! $existingReminders->has($p['school_month'].'_'.$p['year']))->values();

            if ($periodsToNotify->isEmpty()) {
                $skippedCount++;
                $skippedNames[] = $parent->full_name;

                continue;
            }

            $notificationPeriods = $periodsToNotify->map(function ($p) use ($parent) {
                $schoolMonth = SchoolMonth::from($p['school_month']);

                $students = $parent->students->map(function ($s) use ($p) {
                    $payment = $s->monthlyPayments
                        ->first(fn ($mp) => $mp->school_month->value === $p['school_month'] && $mp->year === $p['year']);

                    return $payment ? ['id' => $s->id, 'name' => $s->full_name, 'amount' => (float) $payment->amount] : null;
                })->filter()->values();

                return [
                    'school_month' => $p['school_month'],
                    'school_year' => $p['year'],
                    'due_date' => $this->resolveDueDate($schoolMonth, $p['year']),
                    'students' => $students,
                ];
            });

            $parent->notify(new PaymentReminderNotification(
                parent: $parent,
                periods: $notificationPeriods,
            ));

            foreach ($periodsToNotify as $p) {
                $key = $p['school_month'].'_'.$p['year'];
                $reminder = $existingReminders->get($key) ?? new ParentPaymentReminder([
                    'parent_user_id' => $parent->id,
                    'branch_id' => $branch->id,
                    'school_month' => $p['school_month'],
                    'school_year' => $p['year'],
                    'send_count' => 0,
                ]);

                $reminder->sent_at = now();
                $reminder->sent_by_user_id = $request->user()->id;
                $reminder->send_count = ($reminder->send_count ?? 0) + 1;
                $reminder->save();
            }

            $sentCount++;
        }

        return response()->json([
            'sent' => $sentCount,
            'skipped' => $skippedCount,
            'skipped_names' => $skippedNames,
        ]);
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

    /** @return array{SchoolMonth|null, int} */
    private function resolveUpcomingMonth(int $reminderDays): array
    {
        $target = now()->addDays($reminderDays);
        $schoolMonth = SchoolMonth::fromMonthNumber($target->month);

        if ($schoolMonth === null) {
            return [null, 0];
        }

        return [$schoolMonth, (int) $target->year];
    }

    private function resolveDueDate(SchoolMonth $schoolMonth, int $year): Carbon
    {
        $monthNumber = $schoolMonth->toMonthNumber();
        // Jan–Mar fall in the next calendar year (school year runs June–March)
        $calendarYear = $monthNumber >= 6 ? $year : $year + 1;

        return Carbon::create($calendarYear, $monthNumber, 1)->endOfMonth();
    }

    /**
     * Base query: parents with unpaid monthly payments for subscription students in this branch.
     * Optionally scoped to a specific school month and/or school year.
     */
    private function buildParentsWithUnpaidPayments(
        object $branch,
        ?string $search = null,
        ?string $schoolMonth = null,
        ?int $schoolYear = null,
    ): Builder {
        $query = ParentUser::with([
            'students' => fn ($q) => $q
                ->where('branch_id', $branch->id)
                ->where('student_type', StudentType::Subscription->value)
                ->with(['monthlyPayments' => fn ($q2) => $q2->where('status', '!=', 'paid')]),
        ])
            ->whereHas('students', fn ($q) => $q
                ->where('branch_id', $branch->id)
                ->where('student_type', StudentType::Subscription->value)
                ->whereHas('monthlyPayments', fn ($q2) => $q2
                    ->where('status', '!=', 'paid')
                    ->when($schoolMonth, fn ($q3) => $q3->where('school_month', $schoolMonth))
                    ->when($schoolYear, fn ($q3) => $q3->where('year', $schoolYear))
                )
            )
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($search) {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('lower(first_name) like ?', [$like])
                ->orWhereRaw('lower(last_name) like ?', [$like])
            );
        }

        return $query;
    }

    /**
     * For a loaded parent (with students.monthlyPayments loaded), return all unique
     * (school_month, year) pairs from unpaid payments as a Collection of arrays.
     *
     * @return Collection<int, array{school_month: string, year: int}>
     */
    private function extractUnpaidPeriods(ParentUser $parent): Collection
    {
        return $parent->students
            ->flatMap(fn ($s) => $s->monthlyPayments)
            ->map(fn ($p) => ['school_month' => $p->school_month->value, 'year' => $p->year])
            ->unique(fn ($p) => $p['school_month'].'_'.$p['year'])
            ->values();
    }
}
