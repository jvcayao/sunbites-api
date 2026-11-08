<?php

namespace Tests\Feature\Contract;

use App\Models\Announcement;
use App\Models\Branch;
use App\Models\BranchMonthlyAmount;
use App\Models\Feedback;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\ParentUser;
use App\Models\PosMenuItem;
use App\Models\PreRegistration;
use App\Models\Student;
use App\Models\StudentContact;
use App\Models\StudentMonthlyPayment;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\Notifications\PreRegistrationNotification;
use Database\Seeders\PermissionSeeder;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route as RouteRegistry;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Proves that no route inside a `role:` group is reachable by a role the group does not list,
 * and that each guard rejects the other guard's tokens (Spec 16 design §4.1.5).
 *
 * One test per role instead of a data provider: PHPUnit 12 runs providers before Laravel boots,
 * so `Route::getRoutes()` is not available there.
 */
class RoleAuthorizationMatrixTest extends ContractTestCase
{
    /**
     * Business-rule 403s for a role the route group admits, keyed by route. Exactly these two
     * (design §4.1.5); any other mismatch is a finding, not a new entry.
     *
     * @var array<string, list<string>>
     */
    private const array BUSINESS_RULE_FORBIDDEN = [
        // `{user}` resolves to the actor, and UserPolicy::deactivate denies self-deactivation.
        'POST api/v1/users/{user}/deactivate' => ['admin'],
        // The route group admits managers, but UserPolicy::update requires `users.manage`,
        // which only the admin role has (PermissionSeeder).
        'POST api/v1/users/{user}/photo' => ['manager'],
    ];

    private Branch $branch;

    /** @var array<string, User> */
    private array $staff = [];

    private Student $student;

    private StudentMonthlyPayment $payment;

    private StudentContact $contact;

    private ParentUser $parent;

    private Order $order;

    private PosMenuItem $menuItem;

    private InventoryItem $inventoryItem;

    private Feedback $feedback;

    private Announcement $announcement;

    private PreRegistration $preRegistration;

    private BranchMonthlyAmount $monthlyAmount;

    private int $depositTransactionId;

    /**
     * The matrix needs one instance of each bound model, not the full contract scenario.
     */
    protected function setUpScenario(): void
    {
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['slug' => 'ant', 'is_active' => true]);

        foreach (['admin', 'manager', 'supervisor', 'cashier'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);
            $this->staff[$role] = $user;
        }

        $this->student = Student::factory()->subscription()->create(['branch_id' => $this->branch->id]);
        $this->payment = StudentMonthlyPayment::factory()->unpaid()->create([
            'student_id' => $this->student->id,
            'school_month' => 'october',
            'year' => 2026,
        ]);
        $this->contact = StudentContact::factory()->create(['student_id' => $this->student->id]);
        $this->parent = ParentUser::factory()->create();
        $this->parent->students()->attach($this->student->id, [
            'wallet_alert_threshold' => 100,
            'linked_at' => now(),
            'linked_by' => $this->staff['admin']->id,
        ]);
        $this->depositTransactionId = $this->student->deposit(10000, [
            'payment_method' => 'cash',
            'reference_number' => null,
            'note' => null,
            'performed_by' => $this->staff['cashier']->id,
        ])->id;

        $this->order = Order::factory()->create([
            'branch_id' => $this->branch->id,
            'student_id' => $this->student->id,
            'cashier_id' => $this->staff['cashier']->id,
        ]);
        $this->menuItem = PosMenuItem::factory()->create(['branch_id' => $this->branch->id]);
        $this->inventoryItem = InventoryItem::factory()->create(['branch_id' => $this->branch->id]);
        $this->menuItem->inventoryItems()->attach($this->inventoryItem->id, ['quantity_used' => 1]);

        $this->feedback = Feedback::create([
            'parent_id' => $this->parent->id,
            'student_id' => $this->student->id,
            'branch_id' => $this->branch->id,
            'rating' => 4,
            'category' => 'Service',
            'message' => 'Friendly staff at lunch today.',
            'is_read' => false,
        ]);
        $this->announcement = Announcement::create([
            'title' => 'Notice',
            'message' => 'Canteen closes early on Friday.',
            'sender_id' => $this->staff['manager']->id,
            'branch_id' => $this->branch->id,
            'recipient_type' => 'parents',
            'recipient_count' => 1,
        ]);
        $this->preRegistration = PreRegistration::factory()->pending()->create(['branch_id' => $this->branch->id]);
        $this->monthlyAmount = BranchMonthlyAmount::create([
            'branch_id' => $this->branch->id,
            'school_month' => 'october',
            'year' => 2026,
            'days' => 21,
            'amount' => 2835,
        ]);

        $this->parent->notify(new AnnouncementNotification($this->announcement));
        foreach ($this->staff as $user) {
            $user->notify(new PreRegistrationNotification($this->preRegistration));
        }
    }

    public function test_admin_route_access(): void
    {
        $this->assertSame([], $this->roleMismatches('admin'));
    }

    public function test_manager_route_access(): void
    {
        $this->assertSame([], $this->roleMismatches('manager'));
    }

    public function test_supervisor_route_access(): void
    {
        $this->assertSame([], $this->roleMismatches('supervisor'));
    }

    public function test_cashier_route_access(): void
    {
        $this->assertSame([], $this->roleMismatches('cashier'));
    }

    public function test_parent_routes_reject_missing_and_staff_tokens(): void
    {
        $staffToken = $this->staff['admin']->createToken('t', ['staff'])->plainTextToken;
        $mismatches = [];

        foreach ($this->routesWithMiddleware('auth:parents') as $route) {
            $mismatches = [
                ...$mismatches,
                ...$this->unauthenticatedMismatches($route, null, 'no token'),
                ...$this->unauthenticatedMismatches($route, $staffToken, 'staff token'),
            ];
        }

        $this->assertSame([], $mismatches);
    }

    public function test_staff_routes_reject_missing_and_parent_tokens(): void
    {
        $parentToken = $this->parent->createToken('t', ['parent'])->plainTextToken;
        $mismatches = [];

        foreach ($this->routesWithMiddleware('auth:sanctum') as $route) {
            $mismatches = [
                ...$mismatches,
                ...$this->unauthenticatedMismatches($route, null, 'no token'),
                ...$this->unauthenticatedMismatches($route, $parentToken, 'parent token'),
            ];
        }

        $this->assertSame([], $mismatches);
    }

    /**
     * Calls every `role:` route as the given role with an empty body. Each request runs inside a
     * savepoint that is rolled back, so a destructive route cannot change what the next one sees.
     *
     * @return list<string>
     */
    private function roleMismatches(string $role): array
    {
        Mail::fake();
        Notification::fake();
        Storage::fake('private');
        Storage::fake('public');

        $mismatches = [];

        foreach (RouteRegistry::getRoutes()->getRoutes() as $route) {
            $allowedRoles = $this->allowedRoles($route);

            if ($allowedRoles === null) {
                continue;
            }

            $routeKey = $this->routeKey($route);
            $actor = $this->staff[$role]->fresh();
            Sanctum::actingAs($actor, ['staff']);

            DB::beginTransaction();

            try {
                $status = $this->withHeaders(['X-Branch-Id' => (string) $this->branch->id])
                    ->json($this->routeMethod($route), '/'.$this->resolveUri($route->uri(), $actor), [])
                    ->getStatusCode();
            } finally {
                DB::rollBack();
            }

            $expectForbidden = ! in_array($role, $allowedRoles, true)
                || in_array($role, self::BUSINESS_RULE_FORBIDDEN[$routeKey] ?? [], true);

            if ($expectForbidden && $status !== 403) {
                $mismatches[] = "{$routeKey}: expected 403, got {$status}";
            }

            if (! $expectForbidden && $status === 403) {
                $mismatches[] = "{$routeKey}: expected not 403, got 403";
            }
        }

        return $mismatches;
    }

    /**
     * @return list<string>
     */
    private function unauthenticatedMismatches(Route $route, ?string $token, string $label): array
    {
        app('auth')->forgetGuards();

        $headers = $token === null ? [] : ['Authorization' => "Bearer {$token}"];
        $status = $this->withHeaders($headers)
            ->json($this->routeMethod($route), '/'.$this->resolveUri($route->uri(), $this->staff['admin']), [])
            ->getStatusCode();

        $this->flushHeaders();

        return $status === 401 ? [] : ["{$this->routeKey($route)} ({$label}): expected 401, got {$status}"];
    }

    /**
     * @return list<string>|null the roles listed in the route's `role:` middleware, or null
     */
    private function allowedRoles(Route $route): ?array
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'role:')) {
                return explode('|', substr($middleware, strlen('role:')));
            }
        }

        return null;
    }

    /**
     * @return list<Route>
     */
    private function routesWithMiddleware(string $middleware): array
    {
        return collect(RouteRegistry::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => in_array($middleware, $route->gatherMiddleware(), true))
            ->values()
            ->all();
    }

    private function routeMethod(Route $route): string
    {
        return collect($route->methods())->first(fn (string $method): bool => $method !== 'HEAD');
    }

    private function routeKey(Route $route): string
    {
        return $this->routeMethod($route).' '.$route->uri();
    }

    private function resolveUri(string $uri, User $actor): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn (array $match): string => (string) match ($match[1]) {
            'item' => str_contains($uri, 'menu-items') ? $this->menuItem->id : $this->inventoryItem->id,
            'student' => $this->student->id,
            'order' => $this->order->id,
            'payment' => $this->payment->id,
            'contact' => $this->contact->id,
            'parent' => $this->parent->id,
            'user' => $actor->id,
            'branch' => $this->branch->id,
            'feedback' => $this->feedback->id,
            'announcement' => $this->announcement->id,
            'preRegistration' => $this->preRegistration->id,
            'branchMonthlyAmount' => $this->monthlyAmount->id,
            'inventoryItem' => $this->inventoryItem->id,
            'key' => 'credit_limit',
            'id' => $actor->notifications()->firstOrFail()->id,
            'transaction' => $this->depositTransactionId,
        }, $uri);
    }
}
