<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\MenuCategory;
use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Http\Requests\Kitchen\ShowSubscriptionReportRequest;
use App\Models\BranchSubscriptionConfig;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SubscriptionReportController extends Controller
{
    public function index(ShowSubscriptionReportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $monthEnum = SchoolMonth::from($validated['month']);
        // `year` is the year the school year starts; payments and orders use the month's calendar year.
        $calendarYear = $monthEnum->calendarYearFor((int) $validated['year']);
        $branch = app('active_branch');
        $status = $validated['status'] ?? null;
        $gradeLevel = $validated['grade_level'] ?? null;
        $search = $validated['search'] ?? null;

        $students = Student::where('branch_id', $branch->id)
            ->where('student_type', 'subscription')
            ->when($gradeLevel, fn ($q) => $q->where('grade_level', $gradeLevel))
            ->when($search, fn ($q) => $q->whereAnyContainsLiteral(['first_name', 'last_name', 'student_number'], $search))
            ->when($status === 'not_recorded', fn ($q) => $q->whereDoesntHave('monthlyPayments', fn ($pq) => $pq
                ->where('school_month', $monthEnum->value)
                ->where('year', $calendarYear)
            ))
            ->when(in_array($status, ['paid', 'unpaid']), fn ($q) => $q->whereHas('monthlyPayments', fn ($pq) => $pq
                ->where('school_month', $monthEnum->value)
                ->where('year', $calendarYear)
                ->where('status', $status)
            ))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20);

        $studentIds = $students->pluck('id')->all();

        $usageRows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('pos_menu_items', 'pos_menu_items.id', '=', 'order_items.pos_menu_item_id')
            ->where('orders.branch_id', $branch->id)
            ->where('orders.payment_method', 'subscription')
            ->where('orders.status', 'completed')
            ->whereYear('orders.created_at', $calendarYear)
            ->whereMonth('orders.created_at', $monthEnum->toMonthNumber())
            ->whereIn('orders.student_id', $studentIds)
            ->select('orders.student_id', 'pos_menu_items.category', DB::raw('SUM(order_items.quantity) as total'))
            ->groupBy('orders.student_id', 'pos_menu_items.category')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->pluck('total', 'category'));

        $paymentRecords = StudentMonthlyPayment::whereIn('student_id', $studentIds)
            ->where('school_month', $monthEnum->value)
            ->where('year', $calendarYear)
            ->get()
            ->keyBy('student_id');

        $config = BranchSubscriptionConfig::forBranch($branch->id);
        $days = config('sunbites.school_months')[$monthEnum->value]['days'];

        $data = $students->through(function (Student $student) use ($monthEnum, $calendarYear, $usageRows, $paymentRecords, $config, $days) {
            $usage = $usageRows->get($student->id, collect());
            $payment = $paymentRecords->get($student->id);

            $paymentStatus = match (true) {
                $payment === null => 'not_recorded',
                $payment->status === 'paid' => 'paid',
                default => 'unpaid',
            };

            $categories = [];
            foreach (MenuCategory::cases() as $category) {
                $allocated = $days * $config->limitForCategory($category);
                $used = (int) ($usage[$category->value] ?? 0);
                $categories[$category->value] = [
                    'allocated' => $allocated,
                    'used' => $used,
                    'remaining' => max(0, $allocated - $used),
                ];
            }

            return [
                'id' => $student->id,
                'full_name' => $student->full_name,
                'student_number' => $student->student_number,
                'grade_level' => $student->grade_level,
                'section' => $student->section,
                'payment_status' => $paymentStatus,
                'subscription_monthly_status' => [
                    'month' => $monthEnum->value,
                    'year' => $calendarYear,
                    'categories' => $categories,
                ],
            ];
        });

        $historical = Student::where('branch_id', $branch->id)
            ->where('student_type', 'non_subscription')
            ->whereHas('monthlyPayments', fn ($q) => $q
                ->where('school_month', $monthEnum->value)
                ->where('year', $calendarYear)
                ->where('status', 'paid')
            )
            ->with(['monthlyPayments' => fn ($q) => $q
                ->where('school_month', $monthEnum->value)
                ->where('year', $calendarYear)
                ->where('status', 'paid'),
            ])
            ->get(['id', 'first_name', 'last_name', 'student_number', 'grade_level', 'section'])
            ->map(fn ($s) => [
                'id' => $s->id,
                'full_name' => $s->full_name,
                'student_number' => $s->student_number,
                'grade_level' => $s->grade_level,
                'section' => $s->section,
                'payment_amount' => (float) ($s->monthlyPayments->first()?->amount ?? 0),
            ]);

        return response()->json([
            'data' => $data->items(),
            'meta' => $this->paginationMeta($students),
            'historical_data' => $historical,
        ]);
    }
}
