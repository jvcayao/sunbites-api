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
            'month' => ['required', Rule::in(array_column(SchoolMonth::cases(), 'value'))],
            'year' => ['required', 'integer', 'min:2020', 'max:2099'],
        ]);

        $monthEnum = SchoolMonth::from($validated['month']);
        $year = (int) $validated['year'];
        $branch = app('active_branch');

        // Paginate subscription students for this branch
        $students = Student::where('branch_id', $branch->id)
            ->where('student_type', 'subscription')
            ->whereNull('deleted_at')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20);

        $studentIds = $students->pluck('id')->all();

        // Single bulk query: sum order item quantities per student per category for the requested month
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

        // Single bulk query: payment status per student for the requested month
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

        return response()->json($data);
    }
}
