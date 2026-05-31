<?php

namespace Tests\Feature\Portal;

use App\Jobs\WalletAlertJob;
use App\Mail\WalletAlertMail;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\ParentUser;
use App\Models\PosMenuItem;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletAlertTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private Student $student;

    private ParentUser $parent;

    private User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);
        $this->student = Student::factory()->nonSubscription()->create(['branch_id' => $this->branch->id]);

        $this->staffUser = User::factory()->create();
        $this->staffUser->assignRole('admin');
        $this->staffUser->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->parent = ParentUser::create([
            'first_name' => 'Alert',
            'last_name' => 'Parent',
            'email' => 'alert@example.com',
            'password' => null,
            'email_verified_at' => now(),
        ]);

        $this->parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->staffUser->id,
            'wallet_alert_threshold' => 100,
        ]);

        $this->student->deposit(50000); // PHP 500.00 initial balance
    }

    public function test_wallet_alert_job_sends_mail_when_balance_drops_below_threshold(): void
    {
        Mail::fake();

        WalletAlertJob::dispatch($this->student->id, 80.00);

        Mail::assertQueued(WalletAlertMail::class, fn ($mail) => $mail->hasTo('alert@example.com'));
    }

    public function test_wallet_alert_job_does_not_send_mail_when_balance_is_above_threshold(): void
    {
        Mail::fake();

        WalletAlertJob::dispatch($this->student->id, 200.00);

        Mail::assertNothingQueued();
    }

    public function test_wallet_alert_job_does_not_send_when_threshold_is_zero(): void
    {
        Mail::fake();

        $this->parent->students()->updateExistingPivot($this->student->id, ['wallet_alert_threshold' => 0]);

        WalletAlertJob::dispatch($this->student->id, 10.00);

        Mail::assertNothingQueued();
    }

    public function test_wallet_alert_job_is_dispatched_after_wallet_checkout(): void
    {
        Queue::fake();

        Sanctum::actingAs($this->staffUser, ['staff']);

        $menuItem = PosMenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'price' => 45,
            'is_available' => true,
        ]);
        $invItem = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 999]);
        $menuItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);

        $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson('/api/v1/pos/checkout', [
                'student_id' => $this->student->id,
                'payment_method' => 'wallet',
                'items' => [['pos_menu_item_id' => $menuItem->id, 'quantity' => 1]],
            ])->assertCreated();

        Queue::assertPushed(WalletAlertJob::class, fn ($job) => $job->studentId === $this->student->id);
    }

    public function test_wallet_alert_job_is_not_dispatched_for_non_wallet_checkout(): void
    {
        Queue::fake();

        Sanctum::actingAs($this->staffUser, ['staff']);

        $menuItem = PosMenuItem::factory()->create([
            'branch_id' => $this->branch->id,
            'price' => 45,
            'is_available' => true,
        ]);
        $invItem = InventoryItem::factory()->create(['branch_id' => $this->branch->id, 'quantity' => 999]);
        $menuItem->inventoryItems()->attach($invItem->id, ['quantity_used' => 1]);

        $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson('/api/v1/pos/checkout', [
                'payment_method' => 'cash',
                'amount_tendered' => 50,
                'items' => [['pos_menu_item_id' => $menuItem->id, 'quantity' => 1]],
            ])->assertCreated();

        Queue::assertNothingPushed();
    }
}
