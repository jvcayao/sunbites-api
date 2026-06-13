<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\SchoolMonth;
use App\Enums\StudentType;
use App\Http\Controllers\Controller;
use App\Models\ParentPaymentReminder;
use App\Models\ParentUser;
use App\Models\SystemConfiguration;
use App\Notifications\PaymentReminderNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderController extends Controller
{
    public function bellCount(Request $request): JsonResponse
    {
        $branch = app('active_branch');
        $reminderDays = SystemConfiguration::getValue('payment_reminder_days', 14);

        [$schoolMonth, $schoolYear] = $this->resolveUpcomingMonth($reminderDays);

        if ($schoolMonth === null) {
            return response()->json(['count' => 0, 'school_month' => null, 'school_year' => null]);
        }

        $alreadySent = ParentPaymentReminder::where('branch_id', $branch->id)
            ->where('school_month', $schoolMonth->value)
            ->where('school_year', $schoolYear)
            ->pluck('parent_user_id')
            ->all();

        $count = ParentUser::whereHas(
            'students',
            fn ($q) => $q->where('branch_id', $branch->id)->where('student_type', StudentType::Subscription->value)
        )
            ->whereNotIn('id', $alreadySent)
            ->count();

        return response()->json([
            'count' => $count,
            'school_month' => $schoolMonth->value,
            'school_year' => $schoolYear,
        ]);
    }

    public function eligibleParents(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branch = app('active_branch');
        $reminderDays = SystemConfiguration::getValue('payment_reminder_days', 14);

        [$schoolMonth, $schoolYear] = $this->resolveUpcomingMonth($reminderDays);

        $query = ParentUser::with([
            'students' => fn ($q) => $q->where('branch_id', $branch->id)
                ->where('student_type', StudentType::Subscription->value)
                ->with(['monthlyPayments' => fn ($q2) => $q2->when(
                    $schoolMonth,
                    fn ($q3) => $q3->where('school_month', $schoolMonth->value)->where('year', $schoolYear)
                )]),
        ])
            ->whereHas(
                'students',
                fn ($q) => $q->where('branch_id', $branch->id)->where('student_type', StudentType::Subscription->value)
            )
            ->orderBy('last_name')
            ->orderBy('first_name');

        if (! empty($validated['search'])) {
            $search = '%'.mb_strtolower($validated['search']).'%';
            $query->where(
                fn ($q) => $q->whereRaw('lower(first_name) like ?', [$search])
                    ->orWhereRaw('lower(last_name) like ?', [$search])
            );
        }

        $parents = $query->paginate($validated['per_page'] ?? 25);

        $sentMap = $schoolMonth
            ? ParentPaymentReminder::where('branch_id', $branch->id)
                ->where('school_month', $schoolMonth->value)
                ->where('school_year', $schoolYear)
                ->whereIn('parent_user_id', collect($parents->items())->pluck('id'))
                ->get()
                ->keyBy('parent_user_id')
            : collect();

        return response()->json([
            'data' => collect($parents->items())->map(fn ($parent) => [
                'id' => $parent->id,
                'full_name' => $parent->full_name,
                'email' => $parent->email,
                'was_sent' => $sentMap->has($parent->id),
                'sent_at' => $sentMap->get($parent->id)?->sent_at?->toDateTimeString(),
                'subscription_students' => $parent->students->map(fn ($s) => [
                    'id' => $s->id,
                    'full_name' => $s->full_name,
                    'student_number' => $s->student_number,
                    'amount' => $s->monthlyPayments->first() ? (float) $s->monthlyPayments->first()->amount : null,
                ]),
            ]),
            'meta' => $this->paginationMeta($parents),
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
        $reminderDays = SystemConfiguration::getValue('payment_reminder_days', 14);

        [$schoolMonth, $schoolYear] = $this->resolveUpcomingMonth($reminderDays);

        abort_unless($schoolMonth !== null, 422, 'No upcoming school month found within the reminder window.');

        $force = $validated['force'] ?? false;
        $sentCount = 0;
        $skippedCount = 0;
        $skippedNames = [];

        $parents = ParentUser::with([
            'students' => fn ($q) => $q->where('branch_id', $branch->id)
                ->where('student_type', StudentType::Subscription->value)
                ->with(['monthlyPayments' => fn ($q2) => $q2->where('school_month', $schoolMonth->value)->where('year', $schoolYear)]),
        ])->whereIn('id', $validated['parent_ids'])->get();

        foreach ($parents as $parent) {
            $alreadySent = ParentPaymentReminder::where('parent_user_id', $parent->id)
                ->where('branch_id', $branch->id)
                ->where('school_month', $schoolMonth->value)
                ->where('school_year', $schoolYear)
                ->exists();

            if ($alreadySent && ! $force) {
                $skippedCount++;
                $skippedNames[] = $parent->full_name;

                continue;
            }

            $students = $parent->students->map(fn ($s) => [
                'name' => $s->full_name,
                'amount' => $s->monthlyPayments->first() ? (float) $s->monthlyPayments->first()->amount : 0,
            ]);

            $dueDate = $this->resolveDueDate($schoolMonth, $schoolYear);

            $parent->notify(new PaymentReminderNotification(
                parent: $parent,
                school_month: $schoolMonth->value,
                school_year: $schoolYear,
                students: $students,
                due_date: $dueDate,
            ));

            ParentPaymentReminder::updateOrCreate(
                [
                    'parent_user_id' => $parent->id,
                    'branch_id' => $branch->id,
                    'school_month' => $schoolMonth->value,
                    'school_year' => $schoolYear,
                ],
                [
                    'sent_at' => now(),
                    'sent_by_user_id' => $request->user()->id,
                ]
            );

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
        $fullYear = $monthNumber >= 6 ? $year : $year;

        return Carbon::create($fullYear, $monthNumber, 1)->endOfMonth();
    }
}
