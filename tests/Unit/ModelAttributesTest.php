<?php

namespace Tests\Unit;

use App\Enums\CreditSettlementMethod;
use App\Enums\CreditTransactionType;
use App\Enums\DayOfWeek;
use App\Enums\EnrollmentStatus;
use App\Enums\FeedbackCategory;
use App\Enums\InventoryLogType;
use App\Enums\MenuCategory;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PreRegistrationStatus;
use App\Enums\SchoolMonth;
use App\Enums\StaffStatus;
use App\Enums\StudentType;
use App\Models\Announcement;
use App\Models\Branch;
use App\Models\BranchMonthlyAmount;
use App\Models\BranchSubscriptionConfig;
use App\Models\CreditTransaction;
use App\Models\Feedback;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Models\MealPlannerWeekVisibility;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ParentPaymentReminder;
use App\Models\ParentUser;
use App\Models\PosMenuItem;
use App\Models\PreRegistration;
use App\Models\PreRegistrationContact;
use App\Models\StaffDailyStatus;
use App\Models\Student;
use App\Models\StudentContact;
use App\Models\StudentMonthlyPayment;
use App\Models\SystemConfiguration;
use App\Models\User;
use App\Models\WalletTopupVoid;
use App\Models\WeeklyMealPlan;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A snapshot of every model's table, mass assignment, serialisation, timestamps, key and casts,
 * so moving properties to Laravel 13 model attributes is provably behaviour-identical
 * (Spec 16 T-16, design §4.5.5).
 */
class ModelAttributesTest extends TestCase
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array{table: string, fillable: list<string>, hidden: list<string>, timestamps: bool, key: string, casts: array<string, string>}  $expected
     */
    #[DataProvider('models')]
    public function test_model_keeps_its_table_fillable_hidden_timestamps_key_and_casts(string $modelClass, array $expected): void
    {
        $model = new $modelClass;

        $this->assertSame($expected, [
            'table' => $model->getTable(),
            'fillable' => $model->getFillable(),
            'hidden' => $model->getHidden(),
            'timestamps' => $model->usesTimestamps(),
            'key' => $model->getKeyName(),
            'casts' => $model->getCasts(),
        ]);
    }

    /**
     * @return array<string, array{class-string<Model>, array{table: string, fillable: list<string>, hidden: list<string>, timestamps: bool, key: string, casts: array<string, string>}}>
     */
    public static function models(): array
    {
        return [
            'Announcement' => [Announcement::class, [
                'table' => 'announcements',
                'fillable' => ['title', 'message', 'sender_id', 'branch_id', 'recipient_type', 'recipient_count'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int'],
            ]],
            'Branch' => [Branch::class, [
                'table' => 'branches',
                'fillable' => ['name', 'slug', 'address', 'phone', 'gcash_number', 'is_active'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'deleted_at' => 'datetime', 'is_active' => 'boolean'],
            ]],
            'BranchMonthlyAmount' => [BranchMonthlyAmount::class, [
                'table' => 'branch_monthly_amounts',
                'fillable' => ['branch_id', 'school_month', 'year', 'days', 'amount'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'school_month' => SchoolMonth::class, 'year' => 'integer', 'days' => 'integer', 'amount' => 'decimal:2'],
            ]],
            'BranchSubscriptionConfig' => [BranchSubscriptionConfig::class, [
                'table' => 'branch_subscription_configs',
                'fillable' => ['branch_id', 'meal_daily_limit', 'snack_daily_limit', 'drink_daily_limit', 'extra_daily_limit'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'meal_daily_limit' => 'integer', 'snack_daily_limit' => 'integer', 'drink_daily_limit' => 'integer', 'extra_daily_limit' => 'integer'],
            ]],
            'CreditTransaction' => [CreditTransaction::class, [
                'table' => 'credit_transactions',
                'fillable' => ['student_id', 'branch_id', 'order_id', 'type', 'amount', 'payment_method', 'reference_number', 'wallet_transaction_id', 'notes', 'performed_by', 'created_at'],
                'hidden' => [],
                'timestamps' => false,
                'key' => 'id',
                'casts' => ['id' => 'int', 'type' => CreditTransactionType::class, 'payment_method' => CreditSettlementMethod::class, 'amount' => 'decimal:2', 'created_at' => 'datetime'],
            ]],
            'Feedback' => [Feedback::class, [
                'table' => 'feedbacks',
                'fillable' => ['parent_id', 'student_id', 'branch_id', 'rating', 'category', 'message', 'is_read', 'admin_reply', 'replied_at'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'category' => FeedbackCategory::class, 'is_read' => 'boolean', 'replied_at' => 'datetime'],
            ]],
            'InventoryItem' => [InventoryItem::class, [
                'table' => 'inventory_items',
                'fillable' => ['branch_id', 'name', 'quantity', 'unit', 'restock_threshold', 'overstock_threshold', 'cost_per_unit', 'is_archived'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'quantity' => 'decimal:2', 'restock_threshold' => 'decimal:2', 'overstock_threshold' => 'decimal:2', 'cost_per_unit' => 'decimal:2', 'is_archived' => 'boolean'],
            ]],
            'InventoryLog' => [InventoryLog::class, [
                'table' => 'inventory_logs',
                'fillable' => ['branch_id', 'inventory_item_id', 'adjusted_by', 'type', 'quantity_change', 'stock_after', 'reason', 'item_name_snapshot', 'order_id', 'created_at'],
                'hidden' => [],
                'timestamps' => false,
                'key' => 'id',
                'casts' => ['id' => 'int', 'type' => InventoryLogType::class, 'quantity_change' => 'decimal:2', 'stock_after' => 'decimal:2', 'order_id' => 'integer', 'created_at' => 'datetime'],
            ]],
            'MealPlannerWeekVisibility' => [MealPlannerWeekVisibility::class, [
                'table' => 'meal_planner_week_visibility',
                'fillable' => ['branch_id', 'school_month', 'week_number', 'visible_to_parents'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'school_month' => SchoolMonth::class, 'visible_to_parents' => 'boolean'],
            ]],
            'Order' => [Order::class, [
                'table' => 'orders',
                'fillable' => ['branch_id', 'student_id', 'cashier_id', 'receipt_number', 'payment_method', 'subtotal', 'discount_amount', 'discount_reason', 'total', 'amount_tendered', 'change_amount', 'reference_number', 'notes', 'is_credit', 'credit_amount', 'points_earned', 'status', 'voided_at', 'voided_by', 'void_reason'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'payment_method' => PaymentMethod::class, 'status' => OrderStatus::class, 'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'total' => 'decimal:2', 'amount_tendered' => 'decimal:2', 'change_amount' => 'decimal:2', 'credit_amount' => 'decimal:2', 'is_credit' => 'boolean', 'points_earned' => 'integer', 'voided_at' => 'datetime'],
            ]],
            'OrderItem' => [OrderItem::class, [
                'table' => 'order_items',
                'fillable' => ['order_id', 'pos_menu_item_id', 'name', 'price', 'quantity', 'line_total'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'price' => 'decimal:2', 'line_total' => 'decimal:2', 'quantity' => 'integer'],
            ]],
            'ParentPaymentReminder' => [ParentPaymentReminder::class, [
                'table' => 'parent_payment_reminders',
                'fillable' => ['parent_user_id', 'branch_id', 'school_month', 'school_year', 'sent_at', 'sent_by_user_id', 'send_count'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'sent_at' => 'datetime', 'send_count' => 'integer'],
            ]],
            'ParentUser' => [ParentUser::class, [
                'table' => 'parents',
                'fillable' => ['first_name', 'last_name', 'email', 'password', 'phone', 'address', 'profile_photo_path', 'email_verified_at', 'disabled_at'],
                'hidden' => ['password', 'remember_token'],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'deleted_at' => 'datetime', 'email_verified_at' => 'datetime', 'disabled_at' => 'datetime', 'password' => 'hashed'],
            ]],
            'PosMenuItem' => [PosMenuItem::class, [
                'table' => 'pos_menu_items',
                'fillable' => ['branch_id', 'name', 'price', 'category', 'is_available', 'sort_order', 'is_subscription_item'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'category' => MenuCategory::class, 'is_available' => 'boolean', 'is_subscription_item' => 'boolean', 'price' => 'decimal:2'],
            ]],
            'PreRegistration' => [PreRegistration::class, [
                'table' => 'pre_registrations',
                'fillable' => ['branch_id', 'first_name', 'last_name', 'student_number', 'grade_level', 'section', 'birthday', 'allergies', 'notes', 'enrollment_type', 'subscription_start_month', 'subscription_start_year', 'subscription_end_month', 'subscription_end_year', 'signatory_name', 'acknowledged_at', 'status', 'approved_by', 'rejected_by', 'rejection_reason', 'processed_at', 'recaptcha_score', 'submitter_ip', 'expires_at', 'duplicate_check_passed_at', 'parent_email_exists', 'parent_phone_exists'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'status' => PreRegistrationStatus::class, 'birthday' => 'date:Y-m-d', 'acknowledged_at' => 'datetime', 'processed_at' => 'datetime', 'expires_at' => 'datetime', 'recaptcha_score' => 'decimal:2', 'duplicate_check_passed_at' => 'datetime', 'parent_email_exists' => 'boolean', 'parent_phone_exists' => 'boolean'],
            ]],
            'PreRegistrationContact' => [PreRegistrationContact::class, [
                'table' => 'pre_registration_contacts',
                'fillable' => ['pre_registration_id', 'full_name', 'relationship', 'phone', 'email', 'address', 'is_primary'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'is_primary' => 'boolean'],
            ]],
            'StaffDailyStatus' => [StaffDailyStatus::class, [
                'table' => 'staff_daily_statuses',
                'fillable' => ['user_id', 'branch_id', 'date', 'status', 'updated_by'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'date' => 'date', 'status' => StaffStatus::class],
            ]],
            'Student' => [Student::class, [
                'table' => 'students',
                'fillable' => ['branch_id', 'student_number', 'first_name', 'last_name', 'grade_level', 'section', 'birthday', 'photo_path', 'allergies', 'notes', 'qr_code', 'student_type', 'enrollment_status', 'enrollment_date', 'points', 'total_spent', 'credit_balance'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'deleted_at' => 'datetime', 'birthday' => 'date', 'enrollment_date' => 'date', 'student_type' => StudentType::class, 'enrollment_status' => EnrollmentStatus::class, 'points' => 'integer', 'total_spent' => 'decimal:2', 'credit_balance' => 'decimal:2'],
            ]],
            'StudentContact' => [StudentContact::class, [
                'table' => 'student_contacts',
                'fillable' => ['student_id', 'full_name', 'relationship', 'phone', 'address', 'email', 'is_primary'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'is_primary' => 'boolean'],
            ]],
            'StudentMonthlyPayment' => [StudentMonthlyPayment::class, [
                'table' => 'student_monthly_payments',
                'fillable' => ['student_id', 'school_month', 'year', 'status', 'amount', 'recorded_at', 'recorded_by', 'voided_at', 'voided_by', 'void_reason'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'school_month' => SchoolMonth::class, 'status' => PaymentStatus::class, 'year' => 'integer', 'amount' => 'decimal:2', 'recorded_at' => 'datetime', 'voided_at' => 'datetime'],
            ]],
            'SystemConfiguration' => [SystemConfiguration::class, [
                'table' => 'system_configurations',
                'fillable' => ['key', 'value', 'type', 'label', 'description'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int'],
            ]],
            'User' => [User::class, [
                'table' => 'users',
                'fillable' => ['first_name', 'last_name', 'middle_name', 'nickname', 'birthday', 'gender', 'civil_status', 'profile_photo_path', 'phone', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship', 'address_line', 'city', 'province', 'zip_code', 'position', 'employment_type', 'date_hired', 'daily_rate', 'sss_number', 'pagibig_number', 'philhealth_number', 'tin_number', 'email', 'password', 'is_active'],
                'hidden' => ['password', 'remember_token', 'sss_number', 'pagibig_number', 'philhealth_number', 'tin_number'],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'deleted_at' => 'datetime', 'email_verified_at' => 'datetime', 'password' => 'hashed', 'birthday' => 'date', 'date_hired' => 'date', 'daily_rate' => 'decimal:2', 'is_active' => 'boolean'],
            ]],
            'WalletTopupVoid' => [WalletTopupVoid::class, [
                'table' => 'wallet_topup_voids',
                'fillable' => ['student_id', 'branch_id', 'wallet_transaction_id', 'refund_wallet_transaction_id', 'credit_transaction_id', 'original_amount', 'voided_amount', 'shortfall_amount', 'void_reason', 'voided_by', 'created_at'],
                'hidden' => [],
                'timestamps' => false,
                'key' => 'id',
                'casts' => ['id' => 'int', 'original_amount' => 'decimal:2', 'voided_amount' => 'decimal:2', 'shortfall_amount' => 'decimal:2', 'created_at' => 'datetime'],
            ]],
            'WeeklyMealPlan' => [WeeklyMealPlan::class, [
                'table' => 'weekly_meal_plans',
                'fillable' => ['branch_id', 'school_month', 'week_number', 'day_of_week', 'ulam', 'vegetables', 'fruit', 'soup', 'snacks'],
                'hidden' => [],
                'timestamps' => true,
                'key' => 'id',
                'casts' => ['id' => 'int', 'school_month' => SchoolMonth::class, 'day_of_week' => DayOfWeek::class],
            ]],
        ];
    }
}
