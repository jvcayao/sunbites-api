<?php

namespace Tests\Feature\Kitchen;

use App\Enums\CreditTransactionType;
use App\Models\Branch;
use App\Models\CreditTransaction;
use App\Models\ParentUser;
use App\Models\Student;
use App\Models\SystemConfiguration;
use App\Models\User;
use App\Models\WalletTopupVoid;
use App\Notifications\CreditChargedNotification;
use App\Services\CreditLedgerService;
use Bavix\Wallet\Models\Transaction;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletTopUpVoidTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $admin;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->admin = $this->staffWithRole('admin');
        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function staffWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user;
    }

    private function asUser(User $user): static
    {
        Sanctum::actingAs($user, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    /**
     * Mirrors WalletController::topUp()'s meta shape exactly.
     */
    private function depositViaTopUp(Student $student, float $amount, User $performer): Transaction
    {
        return $student->deposit((int) round($amount * 100), [
            'payment_method' => 'cash',
            'reference_number' => null,
            'note' => null,
            'performed_by' => $performer->id,
        ]);
    }

    /**
     * Mirrors InlineReloadController::store()'s meta shape exactly (cashier_id, not performed_by).
     */
    private function depositViaInlineReload(Student $student, float $amount, User $performer): Transaction
    {
        return $student->deposit((int) round($amount * 100), [
            'source' => 'pos_inline_reload',
            'payment_method' => 'cash',
            'reference_number' => null,
            'cashier_id' => $performer->id,
            'order_context' => null,
        ]);
    }

    /**
     * A legacy/malformed deposit row with neither performed_by nor cashier_id in meta.
     */
    private function depositWithUnknownPerformer(Student $student, float $amount): Transaction
    {
        return $student->deposit((int) round($amount * 100), [
            'payment_method' => 'cash',
        ]);
    }

    private function voidUrl(Student $student, int|Transaction $transaction): string
    {
        $id = $transaction instanceof Transaction ? $transaction->id : $transaction;

        return "/api/v1/students/{$student->id}/wallet/top-ups/{$id}/void";
    }

    public function test_admin_voids_a_fully_recoverable_top_up(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);

        $response = $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Cashier entered the wrong amount.',
        ]);

        $response->assertOk();
        $response->assertJson([
            'voided_amount' => 100.0,
            'shortfall_amount' => 0.0,
            'new_wallet_balance' => 0.0,
            'new_credit_balance' => 0.0,
        ]);

        $void = WalletTopupVoid::where('wallet_transaction_id', $deposit->id)->sole();
        $this->assertEquals(100.0, (float) $void->voided_amount);
        $this->assertEquals(0.0, (float) $void->shortfall_amount);
        $this->assertNull($void->credit_transaction_id);
        $this->assertNotNull($void->refund_wallet_transaction_id);

        $this->assertDatabaseCount('credit_transactions', 0);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'wallet',
            'description' => 'wallet.topup_voided',
            'subject_id' => $this->student->id,
            'causer_id' => $this->admin->id,
        ]);
    }

    public function test_partial_spend_converts_the_unrecoverable_remainder_to_credit_debt(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 1000.00, $performer);
        $this->student->withdraw(70000); // spend ₱700, leaving ₱300

        $response = $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Partial spend before correction.',
        ]);

        $response->assertOk();
        $response->assertJson([
            'voided_amount' => 300.0,
            'shortfall_amount' => 700.0,
        ]);

        $this->assertEquals(0.0, (float) $this->student->fresh()->load('wallet')->wallet->balanceFloatNum);

        $void = WalletTopupVoid::where('wallet_transaction_id', $deposit->id)->sole();
        $this->assertEquals(300.0, (float) $void->voided_amount);
        $this->assertEquals(700.0, (float) $void->shortfall_amount);
        $this->assertNotNull($void->credit_transaction_id);

        $creditEntry = CreditTransaction::find($void->credit_transaction_id);
        $this->assertSame(CreditTransactionType::Charged, $creditEntry->type);
        $this->assertEquals(700.0, (float) $creditEntry->amount);
        $this->assertEquals(700.0, (float) $this->student->fresh()->credit_balance);
    }

    public function test_full_spend_charges_the_entire_amount_to_credit_without_a_refund_withdrawal(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);
        $this->student->withdraw(10000); // spend the full ₱100

        $response = $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Nothing left to refund.',
        ]);

        $response->assertOk();
        $response->assertJson(['voided_amount' => 0.0, 'shortfall_amount' => 100.0]);

        $void = WalletTopupVoid::where('wallet_transaction_id', $deposit->id)->sole();
        $this->assertNull($void->refund_wallet_transaction_id, 'A zero-amount void must not create a refund transaction.');
        $this->assertEquals(100.0, (float) $void->shortfall_amount);
        $this->assertEquals(100.0, (float) $this->student->fresh()->credit_balance);
    }

    public function test_shortfall_is_exempt_from_the_credit_limit_check(): void
    {
        SystemConfiguration::updateOrCreate(
            ['key' => 'credit_limit'],
            ['value' => '50', 'type' => 'decimal', 'label' => 'Credit Limit (₱)', 'description' => 'test override'],
        );

        // Unrelated pre-existing charge already puts the student near the (now low) limit.
        app(CreditLedgerService::class)->charge($this->student, 40.00, 'unrelated prior charge', $this->admin);

        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);
        $this->student->withdraw(8000); // spend ₱80, leaving ₱20 — shortfall of ₱80

        $response = $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Exemption check.',
        ]);

        $response->assertOk();
        $this->assertEquals(120.0, (float) $this->student->fresh()->credit_balance, 'The shortfall charge must not be blocked by the credit limit.');
    }

    public function test_a_transaction_cannot_be_voided_twice(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);

        $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'First void.',
        ])->assertOk();

        $response = $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Second attempt.',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('message', 'This top-up has already been voided.');

        $this->assertSame(1, WalletTopupVoid::where('wallet_transaction_id', $deposit->id)->count());
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_a_staff_member_cannot_void_their_own_top_up(): void
    {
        $deposit = $this->depositViaTopUp($this->student, 100.00, $this->admin);

        $response = $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Trying to erase my own mistake.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('wallet_topup_voids', 0);
        $this->assertEquals(100.0, (float) $this->student->fresh()->load('wallet')->wallet->balanceFloatNum);
    }

    public function test_a_staff_member_cannot_void_their_own_inline_reload(): void
    {
        $manager = $this->staffWithRole('manager');
        $deposit = $this->depositViaInlineReload($this->student, 100.00, $manager);

        $response = $this->asUser($manager)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Trying to erase my own inline reload.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('wallet_topup_voids', 0);
    }

    public function test_void_is_allowed_when_the_original_performer_is_unknown(): void
    {
        $deposit = $this->depositWithUnknownPerformer($this->student, 100.00);

        $response = $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Legacy row with no recorded performer.',
        ]);

        $response->assertOk();
    }

    public function test_manager_is_blocked_from_voiding_a_top_up_from_a_previous_day(): void
    {
        $manager = $this->staffWithRole('manager');
        $otherPerformer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $otherPerformer);

        DB::table('transactions')->where('id', $deposit->id)->update(['created_at' => now()->subDay()]);

        $response = $this->asUser($manager)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Attempting a stale void.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('wallet_topup_voids', 0);
    }

    public function test_admin_is_not_restricted_by_the_same_day_window(): void
    {
        $otherPerformer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $otherPerformer);

        DB::table('transactions')->where('id', $deposit->id)->update(['created_at' => now()->subDay()]);

        $response = $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Admin voiding an old top-up.',
        ]);

        $response->assertOk();
    }

    public function test_a_different_manager_may_void_a_same_day_top_up(): void
    {
        $managerA = $this->staffWithRole('manager');
        $managerB = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $managerA);

        $response = $this->asUser($managerB)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Same-day cross-manager void.',
        ]);

        $response->assertOk();
    }

    public function test_cashier_is_blocked_by_the_role_gate(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);
        $cashier = $this->staffWithRole('cashier');

        $response = $this->asUser($cashier)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Attempt as cashier.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('wallet_topup_voids', 0);
    }

    /**
     * The route sits in the pre-existing role:admin|manager|supervisor group that already
     * gates /wallet/top-up — a supervisor is let through the role gate and is subject to
     * the same same-day window as a manager (Requirement 4, amended after the implementer's
     * own role-gate test caught the original admin|manager-only assumption being wrong).
     */
    public function test_supervisor_is_allowed_through_the_role_gate_for_a_same_day_void(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);
        $supervisor = $this->staffWithRole('supervisor');

        $response = $this->asUser($supervisor)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Same-day void by a supervisor.',
        ]);

        $response->assertOk();
    }

    public function test_supervisor_is_blocked_from_voiding_a_top_up_from_a_previous_day(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);
        DB::table('transactions')->where('id', $deposit->id)->update(['created_at' => now()->subDay()]);

        $supervisor = $this->staffWithRole('supervisor');

        $response = $this->asUser($supervisor)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Stale void attempt by a supervisor.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('wallet_topup_voids', 0);
    }

    public function test_voiding_another_students_transaction_id_returns_not_found(): void
    {
        $otherStudent = Student::factory()->create(['branch_id' => $this->branch->id]);
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);

        $response = $this->asUser($this->admin)->postJson($this->voidUrl($otherStudent, $deposit), [
            'reason' => 'Wrong student route.',
        ]);

        $response->assertNotFound();
    }

    public function test_voiding_any_transaction_id_for_a_student_with_no_wallet_at_all_returns_not_found(): void
    {
        // bavix never persists a wallet row until the first deposit/withdraw — a student
        // with no wallet activity yet has no row in `wallets`, so wallet?->id resolves to
        // null (accessing ->wallet itself returns bavix's transient, unsaved default
        // instance rather than null, but that instance's id attribute is unset).
        $freshStudent = Student::factory()->create(['branch_id' => $this->branch->id]);
        $this->assertFalse($freshStudent->wallet->exists);
        $this->assertNull($freshStudent->wallet->id);

        $response = $this->asUser($this->admin)->postJson($this->voidUrl($freshStudent, 999999), [
            'reason' => 'No wallet exists yet.',
        ]);

        $response->assertNotFound();
    }

    public function test_a_non_numeric_transaction_segment_returns_not_found_not_a_server_error(): void
    {
        $response = $this->asUser($this->admin)->postJson(
            "/api/v1/students/{$this->student->id}/wallet/top-ups/not-a-number/void",
            ['reason' => 'Malformed URL segment.'],
        );

        $response->assertNotFound();
    }

    public function test_a_transaction_segment_too_large_for_a_native_int_returns_not_found_not_a_server_error(): void
    {
        // Passes the route's whereNumber (digits only) but overflows PHP's native int
        // range, which would throw a TypeError under implicit scalar coercion against a
        // plain `int` parameter.
        $response = $this->asUser($this->admin)->postJson(
            "/api/v1/students/{$this->student->id}/wallet/top-ups/99999999999999999999999/void",
            ['reason' => 'Oversized numeric segment.'],
        );

        $response->assertNotFound();
    }

    public function test_voiding_a_withdraw_type_transaction_returns_not_found(): void
    {
        $performer = $this->staffWithRole('manager');
        $this->depositViaTopUp($this->student, 100.00, $performer);
        $purchase = $this->student->withdraw(5000);

        $response = $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $purchase), [
            'reason' => 'Attempt to void a purchase.',
        ]);

        $response->assertNotFound();
    }

    public function test_missing_reason_is_rejected(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);

        $response = $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['reason']);
        $this->assertDatabaseCount('wallet_topup_voids', 0);
    }

    public function test_reason_over_500_characters_is_rejected(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);

        $response = $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => str_repeat('a', 501),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['reason']);
    }

    public function test_reason_containing_html_tags_is_stripped(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);

        $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => '<b>Wrong</b> amount<script>alert(1)</script>',
        ])->assertOk();

        $void = WalletTopupVoid::where('wallet_transaction_id', $deposit->id)->sole();
        $this->assertSame('Wrong amountalert(1)', $void->void_reason);
    }

    public function test_the_original_transactions_meta_is_annotated_without_altering_amount_or_type(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);

        $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Annotate the original row.',
        ])->assertOk();

        $void = WalletTopupVoid::where('wallet_transaction_id', $deposit->id)->sole();
        $original = DB::table('transactions')->where('id', $deposit->id)->first();
        $meta = json_decode((string) $original->meta, true);

        $this->assertArrayHasKey('voided_at', $meta);
        $this->assertSame($void->id, $meta['wallet_topup_void_id']);
        $this->assertSame('deposit', $original->type);
        $this->assertEquals(10000, (int) $original->amount, 'The original amount (centavos) must be byte-for-byte unchanged.');
        $this->assertSame('cash', $meta['payment_method'], 'Pre-existing meta keys must survive the annotation update.');
    }

    public function test_a_shortfall_notifies_linked_parents_via_the_existing_credit_charge_notification(): void
    {
        $parent = ParentUser::create([
            'first_name' => 'Test',
            'last_name' => 'Parent',
            'email' => 'void-notify@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);
        $parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->admin->id,
            'wallet_alert_threshold' => 0,
        ]);

        Notification::fake();

        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 1000.00, $performer);
        $this->student->withdraw(70000); // shortfall of ₱700

        $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Shortfall notification check.',
        ])->assertOk();

        Notification::assertSentTo($parent, CreditChargedNotification::class);
    }

    public function test_a_fully_recovered_void_sends_no_credit_charge_notification(): void
    {
        $parent = ParentUser::create([
            'first_name' => 'Test',
            'last_name' => 'Parent',
            'email' => 'void-no-notify@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);
        $parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->admin->id,
            'wallet_alert_threshold' => 0,
        ]);

        Notification::fake();

        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);

        $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Full recovery, no shortfall.',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_a_void_with_shortfall_shows_correctly_in_the_unified_ledger(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 1000.00, $performer);
        $this->student->withdraw(70000); // spend ₱700, shortfall of ₱700

        $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Ledger integration check.',
        ])->assertOk();

        $rows = collect(
            $this->asUser($this->admin)->getJson("/api/v1/students/{$this->student->id}/ledger?entry_type=all")->json('data')
        );

        $depositRow = $rows->firstWhere('wallet_transaction_id', $deposit->id);
        $this->assertNotNull($depositRow);
        $this->assertTrue($depositRow['voided']);

        $reversalRow = $rows->firstWhere('entry_type', 'topup_voided');
        $this->assertNotNull($reversalRow);
        $this->assertSame('debit', $reversalRow['direction']);
        $this->assertSame('Top-up Voided', $reversalRow['entry_label']);
        $this->assertSame('Ledger integration check.', $reversalRow['note']);

        $creditRow = $rows->firstWhere('entry_type', 'credit_charged');
        $this->assertNotNull($creditRow, 'A shortfall must produce a visible credit_charged row.');
        $this->assertEquals(700.0, $creditRow['amount']);
    }

    public function test_the_topup_filter_includes_both_the_deposit_and_its_reversal(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);

        $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Topup filter check.',
        ])->assertOk();

        $rows = collect(
            $this->asUser($this->admin)->getJson("/api/v1/students/{$this->student->id}/ledger?entry_type=topup")->json('data')
        );

        $this->assertTrue($rows->contains('entry_type', 'deposit'));
        $this->assertTrue($rows->contains('entry_type', 'topup_voided'));
    }

    public function test_the_purchase_filter_excludes_the_topup_voided_reversal(): void
    {
        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);

        $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Purchase filter check.',
        ])->assertOk();

        $rows = collect(
            $this->asUser($this->admin)->getJson("/api/v1/students/{$this->student->id}/ledger?entry_type=purchase")->json('data')
        );

        $this->assertFalse(
            $rows->contains('entry_type', 'topup_voided'),
            'The void reversal must not be miscategorized as an ordinary purchase.'
        );
    }

    public function test_the_portal_ledger_surfaces_the_void_with_no_portal_code_changes(): void
    {
        $parent = ParentUser::create([
            'first_name' => 'Portal',
            'last_name' => 'Parent',
            'email' => 'portal-void@example.com',
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);
        $parent->students()->attach($this->student->id, [
            'linked_at' => now(),
            'linked_by' => $this->admin->id,
            'wallet_alert_threshold' => 0,
        ]);

        $performer = $this->staffWithRole('manager');
        $deposit = $this->depositViaTopUp($this->student, 100.00, $performer);

        $this->asUser($this->admin)->postJson($this->voidUrl($this->student, $deposit), [
            'reason' => 'Visible to the parent.',
        ])->assertOk();

        $token = $parent->createToken('portal-token', ['parent'])->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/v1/portal/students/{$this->student->id}/ledger?entry_type=all");

        $response->assertOk();
        $rows = collect($response->json('data'));

        $reversalRow = $rows->firstWhere('entry_type', 'topup_voided');
        $this->assertNotNull($reversalRow, 'The portal ledger must surface the reversal automatically via the shared query/formatter.');
        $this->assertSame('Visible to the parent.', $reversalRow['note'], 'Void reasons are not staff-only notes — parents see them like any other note.');

        $depositRow = $rows->firstWhere('wallet_transaction_id', $deposit->id);
        $this->assertTrue($depositRow['voided']);
    }
}
