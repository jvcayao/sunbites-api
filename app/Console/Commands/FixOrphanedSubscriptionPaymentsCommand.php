<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('subscriptions:fix-orphaned-payments {--execute} {--branch=}')]
#[Description('Clean up orphaned monthly payments on non-subscription students.')]
class FixOrphanedSubscriptionPaymentsCommand extends Command
{
    public function handle(): int
    {
        $execute = $this->option('execute');
        $branchId = $this->option('branch');

        if ($branchId !== null && ! Branch::find($branchId)) {
            $this->error("Branch #{$branchId} not found.");

            return self::FAILURE;
        }

        if (! $execute) {
            $this->info('[DRY RUN] Scanning for non-subscription students with orphaned payments...');
            $this->newLine();
        }

        $totalStudents = 0;
        $totalPaidRetained = 0;
        $action = $execute ? 'deleted' : 'would be deleted';

        $query = Student::withoutBranch()
            ->where('student_type', 'non_subscription')
            ->whereHas('monthlyPayments');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $query->with('monthlyPayments')->chunk(100, function ($students) use ($execute, $action, &$totalStudents, &$totalPaidRetained) {
            foreach ($students as $student) {
                $unpaid = $student->monthlyPayments->filter(fn ($p) => $p->isUnpaid());
                $paid = $student->monthlyPayments->filter(fn ($p) => $p->isPaid());

                // Skip students whose payments are all voided — nothing to delete or retain.
                if ($unpaid->isEmpty() && $paid->isEmpty()) {
                    continue;
                }

                $totalStudents++;

                $deletedMonths = $unpaid->map(fn ($p) => "{$p->school_month->value} {$p->year}")->values()->all();
                $retainedMonths = $paid->map(fn ($p) => "{$p->school_month->value} {$p->year}")->values()->all();

                $this->info("  {$student->full_name}");

                if ($unpaid->isNotEmpty()) {
                    $amount = $unpaid->sum('amount');
                    $this->line("    → {$unpaid->count()} unpaid month(s) {$action}: ".implode(', ', $deletedMonths)." (₱{$amount})");
                }

                if ($paid->isNotEmpty()) {
                    $totalPaidRetained += $paid->count();
                    foreach ($paid as $p) {
                        $this->warn("    ⚠  Paid month retained: {$p->school_month->value} {$p->year} ₱{$p->amount} — review and void manually if refund needed");
                    }
                }

                if ($execute && $unpaid->isNotEmpty()) {
                    DB::transaction(function () use ($student, $unpaid, $paid, $deletedMonths, $retainedMonths) {
                        StudentMonthlyPayment::whereIn('id', $unpaid->pluck('id'))->delete();

                        activity('students')
                            ->performedOn($student)
                            ->withProperties([
                                'deleted_count' => $unpaid->count(),
                                'deleted_months' => $deletedMonths,
                                'retained_paid_count' => $paid->count(),
                                'retained_paid_months' => $retainedMonths,
                            ])
                            ->log('students.orphaned_payments_cleaned');
                    });
                }
            }
        });

        if ($totalStudents === 0) {
            $this->info('No non-subscription students with orphaned payments found. Nothing to do.');

            return self::SUCCESS;
        }

        $this->newLine();
        $label = $execute ? 'Cleaned' : 'Found';
        $this->info("{$label}: {$totalStudents} student(s) with orphaned payments.");

        if ($totalPaidRetained > 0) {
            $this->warn("{$totalPaidRetained} paid month(s) retained — review and void manually if refund needed.");
        }

        if (! $execute) {
            $this->newLine();
            $this->line('Run with --execute to apply changes.');
        }

        return self::SUCCESS;
    }
}
