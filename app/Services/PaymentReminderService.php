<?php

namespace App\Services;

use App\Enums\SchoolMonth;
use App\Enums\StudentType;
use App\Models\Branch;
use App\Models\ParentPaymentReminder;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use App\Notifications\PaymentReminderNotification;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who owes subscription payments in a branch, and the reminders sent to them.
 *
 * Every query is limited to the given branch's subscription students. A payment's `year` is the
 * calendar year of its month. A school year runs June to March, so the `school_year` filter (the
 * year the school year starts) covers June–December of that year and January–March of the next.
 */
class PaymentReminderService
{
    /**
     * Base query: parents with unpaid monthly payments for subscription students in this branch.
     * Optionally scoped to a specific school month and/or school year (the year it starts).
     *
     * @return Builder<ParentUser>
     */
    public function parentsWithUnpaidPayments(Branch $branch, ?string $search = null, ?string $schoolMonth = null, ?int $schoolYear = null): Builder
    {
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
                    ->when($schoolYear, fn ($q3) => $schoolMonth
                        ? $q3->where('year', SchoolMonth::from($schoolMonth)->calendarYearFor($schoolYear))
                        : $q3->where(fn ($q) => $q
                            ->where(fn ($a) => $a->whereIn('school_month', SchoolMonth::firstHalfValues())->where('year', $schoolYear))
                            ->orWhere(fn ($b) => $b->whereIn('school_month', SchoolMonth::secondHalfValues())->where('year', $schoolYear + 1))
                        ))
                )
            )
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($search) {
            $term = mb_strtolower($search);
            $query->where(fn ($q) => $q
                ->whereContainsLiteral('lower(first_name)', $term)
                ->whereContainsLiteral('lower(last_name)', $term, 'or')
            );
        }

        return $query;
    }

    /**
     * For a loaded parent (with students.monthlyPayments loaded), return all unique
     * (school_month, year) pairs from unpaid payments whose calendar month has already started.
     * Future months are excluded — they should not be reminded until they are current.
     *
     * @return Collection<int, array{school_month: string, year: int}>
     */
    public function unpaidPeriods(ParentUser $parent): Collection
    {
        $now = now();

        return $parent->students
            ->flatMap(fn ($s) => $s->monthlyPayments)
            ->filter(fn ($p) => Carbon::create($p->year, $p->school_month->toMonthNumber(), 1)->startOfMonth()->lte($now))
            ->map(fn ($p) => ['school_month' => $p->school_month->value, 'year' => $p->year])
            ->unique(fn ($p) => $this->periodKey($p['school_month'], $p['year']))
            ->values();
    }

    public function dueDate(SchoolMonth $schoolMonth, int $year): Carbon
    {
        return Carbon::create($year, $schoolMonth->toMonthNumber(), 1)->endOfMonth();
    }

    /**
     * Parents with at least one unpaid period that has not been reminded yet.
     */
    public function bellCount(Branch $branch): int
    {
        $parents = $this->parentsWithUnpaidPayments($branch)->get();

        if ($parents->isEmpty()) {
            return 0;
        }

        $sentKeys = ParentPaymentReminder::where('branch_id', $branch->id)
            ->whereIn('parent_user_id', $parents->pluck('id'))
            ->get()
            ->groupBy('parent_user_id')
            ->map(fn ($reminders) => $reminders->map(fn ($r) => $this->periodKey($r->school_month, $r->school_year))->flip());

        return $parents->filter(function (ParentUser $parent) use ($sentKeys) {
            $periods = $this->unpaidPeriods($parent);
            $parentSentKeys = $sentKeys->get($parent->id, collect());

            return $periods->contains(fn ($p) => ! $parentSentKeys->has($this->periodKey($p['school_month'], $p['year'])));
        })->count();
    }

    /**
     * @param  array<string, mixed>  $filters  `search`, `school_month` and `school_year`, each optional
     * @return Collection<int, array<string, mixed>> the enriched rows incl. the private _all_sent/_none_sent keys
     */
    public function eligibleParents(Branch $branch, array $filters): Collection
    {
        $parents = $this->parentsWithUnpaidPayments(
            $branch,
            $filters['search'] ?? null,
            $filters['school_month'] ?? null,
            $filters['school_year'] ?? null,
        )->get();

        $parentIds = $parents->pluck('id');

        $remindersMap = ParentPaymentReminder::where('branch_id', $branch->id)
            ->whereIn('parent_user_id', $parentIds)
            ->get()
            ->groupBy('parent_user_id');

        $now = now();

        return $parents->map(function (ParentUser $parent) use ($remindersMap, $now) {
            $periods = $this->unpaidPeriods($parent);
            $reminders = $remindersMap->get($parent->id, collect());
            $remindersByKey = $reminders->keyBy(fn ($r) => $this->periodKey($r->school_month, $r->school_year));

            $hasOverdue = $periods->contains(function ($p) use ($now) {
                $monthNum = SchoolMonth::from($p['school_month'])->toMonthNumber();

                return Carbon::create($p['year'], $monthNum, 1)->startOfMonth()->lt($now);
            });

            $totalSendCount = $reminders->sum('send_count');
            $allSent = $periods->isNotEmpty() && $periods->every(fn ($p) => $remindersByKey->has($this->periodKey($p['school_month'], $p['year'])));
            $noneSent = $totalSendCount === 0;

            $unpaidPeriods = $periods->map(function ($p) use ($parent, $remindersByKey) {
                $reminder = $remindersByKey->get($this->periodKey($p['school_month'], $p['year']));

                $students = $parent->students->map(function ($s) use ($p) {
                    $payment = $this->paymentForPeriod($s, $p);

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
    }

    /**
     * Remind each parent of every unpaid period that has started, or only of the periods not
     * reminded before unless `$force` is set. Existing reminders for all the parents load in
     * one query (Req 7.4).
     *
     * @param  array<int, int>  $parentIds
     * @return array{sent: int, skipped: int, skipped_names: list<string>}
     */
    public function send(Branch $branch, array $parentIds, bool $force, User $sender): array
    {
        $sentCount = 0;
        $skippedCount = 0;
        $skippedNames = [];

        $parents = ParentUser::with([
            'students' => fn ($q) => $q
                ->where('branch_id', $branch->id)
                ->where('student_type', StudentType::Subscription->value)
                ->with(['monthlyPayments' => fn ($q2) => $q2->where('status', '!=', 'paid')]),
        ])->whereIn('id', $parentIds)->get();

        $remindersByParent = ParentPaymentReminder::where('branch_id', $branch->id)
            ->whereIn('parent_user_id', $parents->pluck('id'))
            ->get()
            ->groupBy('parent_user_id');

        foreach ($parents as $parent) {
            $allPeriods = $this->unpaidPeriods($parent);

            if ($allPeriods->isEmpty()) {
                $skippedCount++;
                $skippedNames[] = $parent->full_name;

                continue;
            }

            $existingReminders = $remindersByParent->get($parent->id, collect())
                ->keyBy(fn ($r) => $this->periodKey($r->school_month, $r->school_year));

            $periodsToNotify = $force
                ? $allPeriods
                : $allPeriods->filter(fn ($p) => ! $existingReminders->has($this->periodKey($p['school_month'], $p['year'])))->values();

            if ($periodsToNotify->isEmpty()) {
                $skippedCount++;
                $skippedNames[] = $parent->full_name;

                continue;
            }

            foreach ($periodsToNotify as $p) {
                $schoolMonth = SchoolMonth::from($p['school_month']);

                $students = $parent->students->map(function ($s) use ($p) {
                    $payment = $this->paymentForPeriod($s, $p);

                    return $payment ? ['id' => $s->id, 'full_name' => $s->full_name, 'amount' => (float) $payment->amount] : null;
                })->filter()->values();

                $parent->notify(new PaymentReminderNotification(
                    parent: $parent,
                    schoolMonth: $p['school_month'],
                    schoolYear: $p['year'],
                    dueDate: $this->dueDate($schoolMonth, $p['year']),
                    students: $students,
                ));
            }

            foreach ($periodsToNotify as $p) {
                $reminder = $existingReminders->get($this->periodKey($p['school_month'], $p['year'])) ?? new ParentPaymentReminder([
                    'parent_user_id' => $parent->id,
                    'branch_id' => $branch->id,
                    'school_month' => $p['school_month'],
                    'school_year' => $p['year'],
                    'send_count' => 0,
                ]);

                $reminder->sent_at = now();
                $reminder->sent_by_user_id = $sender->id;
                $reminder->send_count = ($reminder->send_count ?? 0) + 1;
                $reminder->save();
            }

            $sentCount++;
        }

        return [
            'sent' => $sentCount,
            'skipped' => $skippedCount,
            'skipped_names' => $skippedNames,
        ];
    }

    /**
     * The key that matches an unpaid period (`school_month`, `year`) to a reminder (`school_month`, `school_year`).
     */
    private function periodKey(string $schoolMonth, int|string $year): string
    {
        return $schoolMonth.'_'.$year;
    }

    /**
     * The student's loaded monthly payment for this unpaid period, if any.
     *
     * @param  array{school_month: string, year: int}  $period
     */
    private function paymentForPeriod(Student $student, array $period): ?StudentMonthlyPayment
    {
        return $student->monthlyPayments
            ->first(fn ($mp) => $mp->school_month->value === $period['school_month'] && $mp->year === $period['year']);
    }
}
