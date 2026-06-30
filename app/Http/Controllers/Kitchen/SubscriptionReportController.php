<?php

namespace App\Http\Controllers\Kitchen;

use App\Enums\MenuCategory;
use App\Enums\SchoolMonth;
use App\Http\Controllers\Controller;
use App\Models\BranchSubscriptionConfig;
use App\Models\Student;
use App\Models\StudentMonthlyPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubscriptionReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', Rule::enum(SchoolMonth::class)],
            'year' => ['required', 'integer', 'min:2020', 'max:2099'],
            'status' => ['sometimes', Rule::in(['paid', 'unpaid', 'not_recorded'])],
            'grade_level' => ['sometimes', 'nullable', 'string', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $monthEnum = SchoolMonth::from($validated['month']);
        $year = (int) $validated['year'];
        $branch = app('active_branch');
        $status = $validated['status'] ?? null;
        $gradeLevel = $validated['grade_level'] ?? null;
        $search = $validated['search'] ?? null;

        $students = Student::where('branch_id', $branch->id)
            ->where('student_type', 'subscription')
            ->when($gradeLevel, fn ($q) => $q->where('grade_level', $gradeLevel))
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('student_number', 'like', "%{$search}%");
            }))
            ->when($status === 'not_recorded', fn ($q) => $q->whereDoesntHave('monthlyPayments', fn ($pq) => $pq
                ->where('school_month', $monthEnum->value)
                ->where('year', $year)
            ))
            ->when(in_array($status, ['paid', 'unpaid']), fn ($q) => $q->whereHas('monthlyPayments', fn ($pq) => $pq
                ->where('school_month', $monthEnum->value)
                ->where('year', $year)
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
            ->whereYear('orders.created_at', $year)
            ->whereMonth('orders.created_at', $monthEnum->toMonthNumber())
            ->whereIn('orders.student_id', $studentIds)
            ->select('orders.student_id', 'pos_menu_items.category', DB::raw('SUM(order_items.quantity) as total'))
            ->groupBy('orders.student_id', 'pos_menu_items.category')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($rows) => $rows->pluck('total', 'category'));

        $paymentRecords = StudentMonthlyPayment::whereIn('student_id', $studentIds)
            ->where('school_month', $monthEnum->value)
            ->where('year', $year)
            ->get()
            ->keyBy('student_id');

        $config = BranchSubscriptionConfig::forBranch($branch->id);
        $days = config('sunbites.school_months')[$monthEnum->value]['days'];

        $data = $students->through(function (Student $student) use ($monthEnum, $year, $usageRows, $paymentRecords, $config, $days) {
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
                    'year' => $year,
                    'categories' => $categories,
                ],
            ];
        });

        $historical = Student::where('branch_id', $branch->id)
            ->where('student_type', 'non_subscription')
            ->whereHas('monthlyPayments', fn ($q) => $q
                ->where('school_month', $monthEnum->value)
                ->where('year', $year)
                ->where('status', 'paid')
            )
            ->with(['monthlyPayments' => fn ($q) => $q
                ->where('school_month', $monthEnum->value)
                ->where('year', $year)
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
