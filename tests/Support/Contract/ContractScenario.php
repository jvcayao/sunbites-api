<?php

namespace Tests\Support\Contract;

use App\Enums\InventoryLogType;
use App\Enums\PreRegistrationStatus;
use App\Models\Announcement;
use App\Models\Branch;
use App\Models\BranchMonthlyAmount;
use App\Models\Feedback;
use App\Models\InventoryItem;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ParentPaymentReminder;
use App\Models\ParentUser;
use App\Models\PosMenuItem;
use App\Models\PreRegistration;
use App\Models\PreRegistrationContact;
use App\Models\Student;
use App\Models\StudentContact;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use App\Models\WalletTopupVoid;
use App\Models\WeeklyMealPlan;
use App\Notifications\AnnouncementNotification;
use App\Services\CreditLedgerService;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SystemConfigurationSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One deterministic data set for every contract test (Spec 16 design §4.1.3).
 *
 * Every optional field is filled explicitly, because a factory's random `optional()` value
 * would flip a recorded shape between "null" and "string" from one run to the next.
 */
class ContractScenario
{
    public Branch $branchA;

    public Branch $branchB;

    public User $admin;

    public User $manager;

    public User $supervisor;

    public User $cashier;

    public User $managerB;

    public Student $subscriptionStudent;

    public Student $nonSubscriptionStudent;

    public Student $deletedStudent;

    public Student $branchBStudent;

    public StudentContact $primaryContact;

    public StudentContact $secondaryContact;

    public StudentContact $nonSubscriptionContact;

    public StudentContact $branchBContact;

    public ParentUser $parent;

    public ParentUser $unactivatedParent;

    public ParentUser $branchBParent;

    public StudentMonthlyPayment $junePaidPayment;

    public StudentMonthlyPayment $octoberUnpaidPayment;

    public StudentMonthlyPayment $januaryUnpaidPayment;

    public StudentMonthlyPayment $novemberVoidedPayment;

    public StudentMonthlyPayment $branchBPayment;

    public InventoryItem $inventoryItem;

    public InventoryItem $archivedInventoryItem;

    public InventoryItem $branchBInventoryItem;

    public PosMenuItem $menuItem;

    public PosMenuItem $branchBMenuItem;

    public Order $cashOrder;

    public Order $walletOrder;

    public Order $creditOrder;

    public Order $voidedOrder;

    public Order $branchBOrder;

    /** The ₱500 top-up the cashier made; still voidable. */
    public int $depositTransactionId;

    /** The ₱100 top-up the manager voided through the real endpoint. */
    public int $voidedDepositTransactionId;

    public WalletTopupVoid $topupVoid;

    public BranchMonthlyAmount $monthlyAmount;

    public BranchMonthlyAmount $branchBMonthlyAmount;

    public Announcement $announcement;

    public Announcement $branchBAnnouncement;

    public Feedback $feedback;

    public Feedback $branchBFeedback;

    public PreRegistration $pendingPreRegistration;

    public PreRegistration $approvedPreRegistration;

    public PreRegistration $rejectedPreRegistration;

    public PreRegistration $expiredPreRegistration;

    public PreRegistration $branchBPreRegistration;

    public ParentPaymentReminder $paymentReminder;

    private function __construct(private TestCase $test) {}

    public static function build(TestCase $test, string $now = '2026-10-15 09:30:00'): self
    {
        $scenario = new self($test);

        $test->travelTo(Carbon::parse($now));
        $test->seed(PermissionSeeder::class);
        $test->seed(SystemConfigurationSeeder::class);

        $scenario->createBranchesAndStaff();
        $scenario->createStudentsAndParents();
        $scenario->createMenuAndInventory();
        $scenario->createWallets();
        $scenario->createOrders();
        $scenario->createPayments();
        $scenario->createBranchConfiguration();
        $scenario->createCommunication();
        $scenario->createPreRegistrations();
        $scenario->voidTopUpThroughTheEndpoint();

        return $scenario;
    }

    /**
     * Authenticates a staff member and sends the branch A header, as the POS does.
     */
    public function asStaff(User $user): TestCase
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->test->withHeaders(['X-Branch-Id' => (string) $this->branchA->id]);
    }

    /**
     * Authenticates a parent on the `parents` guard. The portal sends no branch header.
     */
    public function asParent(ParentUser $parent): TestCase
    {
        Sanctum::actingAs($parent, ['parent'], 'parents');

        return $this->test;
    }

    private function createBranchesAndStaff(): void
    {
        $this->branchA = Branch::factory()->create([
            'name' => 'Antipolo',
            'slug' => 'ant',
            'address' => 'Sumulong Highway, Antipolo City',
            'phone' => '0286001234',
            'gcash_number' => '09170000001',
            'is_active' => true,
        ]);
        $this->branchB = Branch::factory()->create([
            'name' => 'Binangonan',
            'slug' => 'bin',
            'address' => 'Manila East Road, Binangonan',
            'phone' => '0286005678',
            'gcash_number' => '09170000002',
            'is_active' => true,
        ]);

        $this->admin = $this->staff('admin', 'Alma', 'Aquino', $this->branchA);
        $this->manager = $this->staff('manager', 'Marco', 'Mendoza', $this->branchA);
        $this->supervisor = $this->staff('supervisor', 'Sofia', 'Salazar', $this->branchA);
        $this->cashier = $this->staff('cashier', 'Carlo', 'Cruz', $this->branchA);
        $this->managerB = $this->staff('manager', 'Bea', 'Bautista', $this->branchB);
    }

    private function staff(string $role, string $firstName, string $lastName, Branch $branch): User
    {
        $user = User::factory()->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'middle_name' => 'Reyes',
            'nickname' => $firstName,
            'email' => strtolower("{$firstName}.{$lastName}@sunbites.test"),
            'birthday' => '1990-05-20',
            'gender' => 'female',
            'civil_status' => 'single',
            'phone' => '09171234567',
            'emergency_contact_name' => 'Rosa Reyes',
            'emergency_contact_phone' => '09179876543',
            'emergency_contact_relationship' => 'Mother',
            'address_line' => '12 Mabini Street',
            'city' => 'Antipolo',
            'province' => 'Rizal',
            'zip_code' => '1870',
            'position' => ucfirst($role),
            'employment_type' => 'full_time',
            'date_hired' => '2024-06-01',
            'daily_rate' => 650,
            'sss_number' => '34-1234567-8',
            'pagibig_number' => '1212-3434-5656',
            'philhealth_number' => '12-345678901-2',
            'tin_number' => '123-456-789-000',
        ]);
        $user->assignRole($role);
        $user->branches()->attach($branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user;
    }

    private function createStudentsAndParents(): void
    {
        $this->subscriptionStudent = Student::factory()->subscription()->create([
            'branch_id' => $this->branchA->id,
            'student_number' => 'ANT-2026-001',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'grade_level' => 'Grade 3',
            'section' => 'Rizal',
            'birthday' => '2017-04-12',
            'photo_path' => 'photos/students/contract-juan.jpg',
            'allergies' => 'Peanuts',
            'notes' => 'Prefers rice meals.',
            'qr_code' => 'SB-CONTRACTJUAN1',
            'enrollment_date' => '2026-06-01',
        ]);
        $this->nonSubscriptionStudent = Student::factory()->nonSubscription()->create([
            'branch_id' => $this->branchA->id,
            'student_number' => 'ANT-2026-002',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'grade_level' => 'Grade 5',
            'section' => 'Mabini',
            'birthday' => '2015-09-03',
            'allergies' => 'Shellfish',
            'notes' => 'Brings packed lunch on Fridays.',
            'qr_code' => 'SB-CONTRACTMARIA',
            'enrollment_date' => '2026-06-01',
        ]);
        $this->deletedStudent = Student::factory()->nonSubscription()->create([
            'branch_id' => $this->branchA->id,
            'student_number' => 'ANT-2026-003',
            'first_name' => 'Pedro',
            'last_name' => 'Reyes',
            'grade_level' => 'Grade 1',
            'section' => 'Luna',
            'birthday' => '2019-01-22',
            'allergies' => 'None',
            'notes' => 'Transferred out.',
            'qr_code' => 'SB-CONTRACTPEDRO',
            'enrollment_date' => '2026-06-01',
        ]);
        $this->deletedStudent->delete();
        $this->branchBStudent = Student::factory()->subscription()->create([
            'branch_id' => $this->branchB->id,
            'student_number' => 'BIN-2026-001',
            'first_name' => 'Ana',
            'last_name' => 'Lopez',
            'grade_level' => 'Grade 2',
            'section' => 'Silang',
            'birthday' => '2018-07-30',
            'photo_path' => 'photos/students/contract-ana.jpg',
            'allergies' => 'Milk',
            'notes' => 'Branch B student.',
            'qr_code' => 'SB-CONTRACTANA01',
            'enrollment_date' => '2026-06-01',
        ]);

        $this->parent = ParentUser::factory()->create([
            'first_name' => 'Elena',
            'last_name' => 'Dela Cruz',
            'email' => 'elena.delacruz@example.test',
            'phone' => '09181112222',
            'address' => '45 Rizal Street, Antipolo',
        ]);
        $this->unactivatedParent = ParentUser::factory()->unactivated()->create([
            'first_name' => 'Ramon',
            'last_name' => 'Santos',
            'email' => 'ramon.santos@example.test',
            'phone' => '09183334444',
            'address' => '7 Luna Street, Antipolo',
        ]);
        $this->branchBParent = ParentUser::factory()->create([
            'first_name' => 'Liza',
            'last_name' => 'Lopez',
            'email' => 'liza.lopez@example.test',
            'phone' => '09185556666',
            'address' => '3 Silang Street, Binangonan',
        ]);

        $this->parent->students()->attach($this->subscriptionStudent->id, [
            'wallet_alert_threshold' => 100,
            'linked_at' => now(),
            'linked_by' => $this->admin->id,
        ]);
        $this->unactivatedParent->students()->attach($this->nonSubscriptionStudent->id, [
            'wallet_alert_threshold' => 50,
            'linked_at' => now(),
            'linked_by' => $this->admin->id,
        ]);
        $this->branchBParent->students()->attach($this->branchBStudent->id, [
            'wallet_alert_threshold' => 100,
            'linked_at' => now(),
            'linked_by' => $this->managerB->id,
        ]);

        $this->primaryContact = StudentContact::factory()->create([
            'student_id' => $this->subscriptionStudent->id,
            'full_name' => 'Elena Dela Cruz',
            'relationship' => 'Mother',
            'phone' => '09181112222',
            'address' => '45 Rizal Street, Antipolo',
            'email' => $this->parent->email,
            'is_primary' => true,
        ]);
        $this->secondaryContact = StudentContact::factory()->create([
            'student_id' => $this->subscriptionStudent->id,
            'full_name' => 'Jose Dela Cruz',
            'relationship' => 'Father',
            'phone' => '09187778888',
            'address' => '45 Rizal Street, Antipolo',
            'email' => null,
            'is_primary' => false,
        ]);
        $this->nonSubscriptionContact = StudentContact::factory()->create([
            'student_id' => $this->nonSubscriptionStudent->id,
            'full_name' => 'Ramon Santos',
            'relationship' => 'Father',
            'phone' => '09183334444',
            'address' => '7 Luna Street, Antipolo',
            'email' => $this->unactivatedParent->email,
            'is_primary' => true,
        ]);
        $this->branchBContact = StudentContact::factory()->create([
            'student_id' => $this->branchBStudent->id,
            'full_name' => 'Liza Lopez',
            'relationship' => 'Mother',
            'phone' => '09185556666',
            'address' => '3 Silang Street, Binangonan',
            'email' => $this->branchBParent->email,
            'is_primary' => true,
        ]);
    }

    private function createMenuAndInventory(): void
    {
        $this->inventoryItem = InventoryItem::factory()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Rice',
            'quantity' => 100,
            'unit' => 'kg',
            'restock_threshold' => 20,
            'overstock_threshold' => 500,
            'cost_per_unit' => 52.50,
        ]);
        $this->archivedInventoryItem = InventoryItem::factory()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Old Juice Concentrate',
            'quantity' => 4,
            'unit' => 'liters',
            'restock_threshold' => 2,
            'overstock_threshold' => 40,
            'cost_per_unit' => 85,
            'is_archived' => true,
        ]);
        $this->branchBInventoryItem = InventoryItem::factory()->create([
            'branch_id' => $this->branchB->id,
            'name' => 'Branch B Rice',
            'quantity' => 80,
            'unit' => 'kg',
            'restock_threshold' => 20,
            'overstock_threshold' => 400,
            'cost_per_unit' => 52.50,
        ]);

        $this->menuItem = PosMenuItem::factory()->subscriptionEligible()->create([
            'branch_id' => $this->branchA->id,
            'name' => 'Chicken Adobo Meal',
            'price' => 135,
            'category' => 'meal',
            'is_available' => true,
            'sort_order' => 1,
        ]);
        $this->menuItem->inventoryItems()->attach($this->inventoryItem->id, ['quantity_used' => 1]);

        $this->branchBMenuItem = PosMenuItem::factory()->subscriptionEligible()->create([
            'branch_id' => $this->branchB->id,
            'name' => 'Branch B Pork Menudo',
            'price' => 140,
            'category' => 'meal',
            'is_available' => true,
            'sort_order' => 1,
        ]);
        $this->branchBMenuItem->inventoryItems()->attach($this->branchBInventoryItem->id, ['quantity_used' => 1]);

        InventoryLog::create([
            'branch_id' => $this->branchA->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'adjusted_by' => $this->supervisor->id,
            'type' => InventoryLogType::Manual,
            'quantity_change' => -2,
            'stock_after' => 100,
            'item_name_snapshot' => $this->inventoryItem->name,
            'reason' => 'Spoilage count',
            'created_at' => now()->subHour(),
        ]);
    }

    private function createWallets(): void
    {
        $this->depositTransactionId = $this->subscriptionStudent->deposit(50000, [
            'payment_method' => 'cash',
            'reference_number' => null,
            'note' => 'Contract scenario',
            'performed_by' => $this->cashier->id,
        ])->id;

        $this->voidedDepositTransactionId = $this->subscriptionStudent->deposit(10000, [
            'payment_method' => 'gcash',
            'reference_number' => 'GC123456',
            'note' => null,
            'performed_by' => $this->cashier->id,
        ])->id;
    }

    private function createOrders(): void
    {
        $this->cashOrder = $this->order('ANT-2026-000001', null, [
            'payment_method' => 'cash',
            'subtotal' => 150,
            'discount_amount' => 15,
            'discount_reason' => 'Senior discount',
            'total' => 135,
            'amount_tendered' => 200,
            'change_amount' => 65,
            'notes' => 'Walk-in customer',
        ], 99);

        $this->walletOrder = Order::factory()->wallet()->create([
            ...$this->orderAttributes('ANT-2026-000002', $this->subscriptionStudent),
            'notes' => 'Lunch',
        ]);
        $this->subscriptionStudent->withdraw(13500);
        $this->orderLines($this->walletOrder, 98);

        $this->creditOrder = Order::factory()->credit()->create([
            ...$this->orderAttributes('ANT-2026-000003', $this->subscriptionStudent),
            'notes' => 'Lunch on credit',
        ]);
        app(CreditLedgerService::class)->charge($this->subscriptionStudent, 50.00, "order {$this->creditOrder->receipt_number}", $this->cashier);
        $this->subscriptionStudent->withdraw(8500);
        $this->orderLines($this->creditOrder, 97);

        $this->voidedOrder = Order::factory()->voided()->create([
            ...$this->orderAttributes('ANT-2026-000004', $this->nonSubscriptionStudent),
            'payment_method' => 'gcash',
            'reference_number' => 'GC778899',
            'notes' => 'Wrong item',
            'voided_by' => $this->manager->id,
        ]);
        $this->orderLines($this->voidedOrder, 96);

        $this->branchBOrder = Order::factory()->create([
            'branch_id' => $this->branchB->id,
            'student_id' => $this->branchBStudent->id,
            'cashier_id' => $this->managerB->id,
            'receipt_number' => 'BIN-2026-000001',
            'payment_method' => 'cash',
            'subtotal' => 140,
            'discount_amount' => 0,
            'total' => 140,
            'amount_tendered' => 140,
            'change_amount' => 0,
            'notes' => 'Branch B order',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(string $receiptNumber, ?Student $student, array $attributes, float $stockAfter): Order
    {
        $order = Order::factory()->create([...$this->orderAttributes($receiptNumber, $student), ...$attributes]);
        $this->orderLines($order, $stockAfter);

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderAttributes(string $receiptNumber, ?Student $student): array
    {
        return [
            'branch_id' => $this->branchA->id,
            'student_id' => $student?->id,
            'cashier_id' => $this->cashier->id,
            'receipt_number' => $receiptNumber,
            'subtotal' => 135,
            'discount_amount' => 0,
            'discount_reason' => null,
            'total' => 135,
            'reference_number' => null,
            'points_earned' => 0,
        ];
    }

    private function orderLines(Order $order, float $stockAfter): void
    {
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'pos_menu_item_id' => $this->menuItem->id,
            'name' => $this->menuItem->name,
            'price' => 135,
            'quantity' => 1,
            'line_total' => 135,
        ]);

        InventoryLog::create([
            'branch_id' => $this->branchA->id,
            'inventory_item_id' => $this->inventoryItem->id,
            'order_id' => $order->id,
            'adjusted_by' => $this->cashier->id,
            'type' => InventoryLogType::Sale,
            'quantity_change' => -1,
            'stock_after' => $stockAfter,
            'item_name_snapshot' => $this->inventoryItem->name,
            'reason' => 'Order #'.$order->receipt_number,
            'created_at' => now(),
        ]);
    }

    private function createPayments(): void
    {
        $student = ['student_id' => $this->subscriptionStudent->id, 'amount' => 2970];

        $this->junePaidPayment = StudentMonthlyPayment::factory()->paid()->create([
            ...$student,
            'school_month' => 'june',
            'year' => 2026,
            'recorded_by' => $this->manager->id,
        ]);
        $this->octoberUnpaidPayment = StudentMonthlyPayment::factory()->unpaid()->create([
            ...$student,
            'school_month' => 'october',
            'year' => 2026,
        ]);
        $this->januaryUnpaidPayment = StudentMonthlyPayment::factory()->unpaid()->create([
            ...$student,
            'school_month' => 'january',
            'year' => 2027,
        ]);
        $this->novemberVoidedPayment = StudentMonthlyPayment::factory()->paid()->create([
            ...$student,
            'school_month' => 'november',
            'year' => 2026,
            'status' => 'voided',
            'recorded_by' => $this->manager->id,
            'voided_at' => now(),
            'voided_by' => $this->manager->id,
            'void_reason' => 'Contract scenario',
        ]);
        $this->branchBPayment = StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->branchBStudent->id,
            'amount' => 2970,
            'school_month' => 'october',
            'year' => 2026,
        ]);
    }

    private function createBranchConfiguration(): void
    {
        $this->monthlyAmount = BranchMonthlyAmount::create([
            'branch_id' => $this->branchA->id,
            'school_month' => 'october',
            'year' => 2026,
            'days' => 21,
            'amount' => 2835,
        ]);
        $this->branchBMonthlyAmount = BranchMonthlyAmount::create([
            'branch_id' => $this->branchB->id,
            'school_month' => 'october',
            'year' => 2026,
            'days' => 20,
            'amount' => 2700,
        ]);

        $meals = [
            'monday' => ['Chicken Adobo', 'Pechay', 'Banana', 'Sinigang', 'Turon'],
            'tuesday' => ['Pork Menudo', 'Kangkong', 'Apple', 'Tinola', 'Banana Cue'],
            'wednesday' => ['Fish Fillet', 'Ampalaya', 'Orange', 'Miso Soup', 'Puto'],
            'thursday' => ['Beef Caldereta', 'Sitaw', 'Papaya', 'Nilaga', 'Kutsinta'],
            'friday' => ['Chicken Curry', 'Okra', 'Watermelon', 'Corn Soup', 'Polvoron'],
        ];

        foreach ($meals as $day => [$ulam, $vegetables, $fruit, $soup, $snacks]) {
            WeeklyMealPlan::create([
                'branch_id' => $this->branchA->id,
                'school_month' => 'june',
                'week_number' => 1,
                'day_of_week' => $day,
                'ulam' => $ulam,
                'vegetables' => $vegetables,
                'fruit' => $fruit,
                'soup' => $soup,
                'snacks' => $snacks,
            ]);
        }
    }

    private function createCommunication(): void
    {
        $this->announcement = Announcement::create([
            'title' => 'Foundation Day',
            'message' => 'The canteen will serve a special lunch on Foundation Day.',
            'sender_id' => $this->manager->id,
            'branch_id' => $this->branchA->id,
            'recipient_type' => 'parents',
            'recipient_count' => 1,
        ]);
        $this->parent->notify(new AnnouncementNotification($this->announcement));

        $this->branchBAnnouncement = Announcement::create([
            'title' => 'Branch B Notice',
            'message' => 'Branch B canteen hours change next week.',
            'sender_id' => $this->managerB->id,
            'branch_id' => $this->branchB->id,
            'recipient_type' => 'parents',
            'recipient_count' => 0,
        ]);

        $this->feedback = Feedback::create([
            'parent_id' => $this->parent->id,
            'student_id' => $this->subscriptionStudent->id,
            'branch_id' => $this->branchA->id,
            'rating' => 4,
            'category' => 'FoodQuality',
            'message' => 'The adobo was great but a little salty today.',
            'is_read' => true,
            'admin_reply' => 'Thank you, we will adjust the seasoning.',
            'replied_at' => now(),
        ]);
        $this->branchBFeedback = Feedback::create([
            'parent_id' => $this->branchBParent->id,
            'student_id' => $this->branchBStudent->id,
            'branch_id' => $this->branchB->id,
            'rating' => 5,
            'category' => 'Service',
            'message' => 'Branch B staff are always friendly.',
            'is_read' => false,
            'admin_reply' => null,
            'replied_at' => null,
        ]);

        $this->paymentReminder = ParentPaymentReminder::create([
            'parent_user_id' => $this->parent->id,
            'branch_id' => $this->branchA->id,
            'school_month' => 'october',
            'school_year' => 2026,
            'sent_at' => now(),
            'sent_by_user_id' => $this->manager->id,
            'send_count' => 1,
        ]);
    }

    private function createPreRegistrations(): void
    {
        $this->pendingPreRegistration = $this->preRegistration($this->branchA, 'Lucas', 'Garcia', 'ANT-2026-101', PreRegistrationStatus::Pending);
        $this->approvedPreRegistration = $this->preRegistration($this->branchA, 'Mia', 'Torres', 'ANT-2026-102', PreRegistrationStatus::Approved, [
            'approved_by' => $this->manager->id,
            'processed_at' => now(),
        ]);
        $this->rejectedPreRegistration = $this->preRegistration($this->branchA, 'Noah', 'Ramos', 'ANT-2026-103', PreRegistrationStatus::Rejected, [
            'rejected_by' => $this->manager->id,
            'rejection_reason' => 'Duplicate submission.',
            'processed_at' => now(),
        ]);
        $this->expiredPreRegistration = $this->preRegistration($this->branchA, 'Ella', 'Flores', 'ANT-2026-104', PreRegistrationStatus::Expired, [
            'expires_at' => now()->subDay(),
        ]);
        $this->branchBPreRegistration = $this->preRegistration($this->branchB, 'Leo', 'Navarro', 'BIN-2026-101', PreRegistrationStatus::Pending);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function preRegistration(
        Branch $branch,
        string $firstName,
        string $lastName,
        string $studentNumber,
        PreRegistrationStatus $status,
        array $attributes = [],
    ): PreRegistration {
        $preRegistration = PreRegistration::factory()->subscription()->create([
            'branch_id' => $branch->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'student_number' => $studentNumber,
            'grade_level' => 'Grade 4',
            'section' => 'Bonifacio',
            'birthday' => '2016-02-14',
            'allergies' => 'Eggs',
            'notes' => 'Needs a seat near the counter.',
            'subscription_start_month' => 'june',
            'subscription_start_year' => 2026,
            'subscription_end_month' => 'march',
            'subscription_end_year' => 2027,
            'signatory_name' => "Parent of {$firstName} {$lastName}",
            'status' => $status,
            'approved_by' => null,
            'rejected_by' => null,
            'rejection_reason' => null,
            'processed_at' => null,
            'duplicate_check_passed_at' => now(),
            'parent_email_exists' => false,
            'parent_phone_exists' => false,
            ...$attributes,
        ]);

        $slug = strtolower($firstName);

        PreRegistrationContact::factory()->primary()->create([
            'pre_registration_id' => $preRegistration->id,
            'full_name' => "Mother of {$firstName}",
            'relationship' => 'Mother',
            'phone' => '09190000001',
            'email' => "{$slug}.mother@example.test",
            'address' => '9 Bonifacio Street',
        ]);
        PreRegistrationContact::factory()->create([
            'pre_registration_id' => $preRegistration->id,
            'full_name' => "Father of {$firstName}",
            'relationship' => 'Father',
            'phone' => '09190000002',
            'email' => "{$slug}.father@example.test",
            'address' => '9 Bonifacio Street',
            'is_primary' => false,
        ]);

        return $preRegistration;
    }

    /**
     * The void goes through HTTP because `VoidWalletTopUpAction` does not exist yet at T-1.
     * Afterwards the request's side effects on the test process are undone: the manager stays
     * authenticated and branch A stays bound in the container otherwise, which would leak into
     * the request each contract test makes.
     */
    private function voidTopUpThroughTheEndpoint(): void
    {
        $this->asStaff($this->manager)
            ->postJson(
                "/api/v1/students/{$this->subscriptionStudent->id}/wallet/top-ups/{$this->voidedDepositTransactionId}/void",
                ['reason' => 'Contract scenario'],
            )
            ->assertOk();

        $this->topupVoid = WalletTopupVoid::where('wallet_transaction_id', $this->voidedDepositTransactionId)->firstOrFail();
        $this->subscriptionStudent->refresh();

        $this->test->flushHeaders();
        app()->forgetInstance('active_branch');
        app('auth')->forgetGuards();
    }
}
