<?php

namespace Tests\Feature\Kitchen;

use App\Enums\CreditSettlementMethod;
use App\Models\Branch;
use App\Models\CreditTransaction;
use App\Models\Student;
use App\Models\User;
use App\Services\CreditLedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentLedgerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private Student $student;

    private User $cashier;

    private CreditLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true]);

        $this->cashier = User::factory()->create(['first_name' => 'Maris', 'last_name' => 'Cayao']);
        $this->cashier->assignRole('cashier');
        $this->cashier->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->student = Student::factory()->create(['branch_id' => $this->branch->id]);
        $this->ledger = app(CreditLedgerService::class);
    }

    private function asCashier(): static
    {
        Sanctum::actingAs($this->cashier, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function ledgerUrl(string $query = ''): string
    {
        return "/api/v1/students/{$this->student->id}/ledger".($query === '' ? '' : "?{$query}");
    }

    /**
     * bavix stamps its own timestamps, so `travelTo` does not reach them. Stamping the rows
     * directly is the only deterministic way to assert cross-source ordering.
     */
    private function stampWalletRows(string $at): void
    {
        DB::table('transactions')->update(['created_at' => $at]);
    }

    private function stampCreditRow(int $id, string $at): void
    {
        DB::table('credit_transactions')->where('id', $id)->update(['created_at' => $at]);
    }

    public function test_it_merges_wallet_and_credit_rows_newest_first(): void
    {
        $this->student->deposit(20000, ['note' => 'Opening float']);
        $this->stampWalletRows(now()->subDays(3)->toDateTimeString());

        $charge = $this->ledger->charge($this->student, 25.00, 'R-1', $this->cashier);
        $this->stampCreditRow($charge->id, now()->subDays(2)->toDateTimeString());

        $settle = $this->ledger->settleWithPayment(
            $this->student, 25.00, CreditSettlementMethod::Cash, null, 'Paid at counter', $this->cashier
        );
        $this->stampCreditRow($settle->id, now()->subDay()->toDateTimeString());

        $response = $this->asCashier()->getJson($this->ledgerUrl());

        $response->assertOk();

        $types = collect($response->json('data'))->pluck('entry_type')->all();

        $this->assertSame(['credit_settled', 'credit_charged', 'deposit'], $types);
    }

    public function test_wallet_amounts_convert_from_minor_units_without_losing_centavos(): void
    {
        $this->student->deposit(2550);

        $response = $this->asCashier()->getJson($this->ledgerUrl('entry_type=topup'));

        $response->assertOk();

        $this->assertSame(25.5, $response->json('data.0.amount'), 'SQLite integer division would truncate this to 25.');
    }

    public function test_withdrawal_amounts_are_returned_positive_with_a_debit_direction(): void
    {
        $this->student->deposit(10000);
        $this->student->withdraw(3550);

        $response = $this->asCashier()->getJson($this->ledgerUrl('entry_type=purchase'));

        $row = $response->json('data.0');

        $this->assertSame(35.5, $row['amount'], 'Amounts are always positive; sign is carried by direction.');
        $this->assertSame('debit', $row['direction']);
        $this->assertSame('Purchase', $row['entry_label']);
    }

    public function test_every_entry_type_reports_the_expected_label_and_direction(): void
    {
        $this->student->deposit(50000);
        $this->ledger->charge($this->student, 100.00, 'R-A', $this->cashier);
        $this->ledger->settleWithPayment($this->student, 30.00, CreditSettlementMethod::Cash, null, null, $this->cashier);
        $this->ledger->waive($this->student, 70.00, 'Remaining balance written off.', $this->cashier);

        $rows = collect($this->asCashier()->getJson($this->ledgerUrl())->json('data'))
            ->keyBy('entry_type');

        $this->assertSame(['Top-up', 'credit'], [$rows['deposit']['entry_label'], $rows['deposit']['direction']]);
        $this->assertSame(['Credit Charged', 'debit'], [$rows['credit_charged']['entry_label'], $rows['credit_charged']['direction']]);
        $this->assertSame(['Credit Paid', 'credit'], [$rows['credit_settled']['entry_label'], $rows['credit_settled']['direction']]);
        $this->assertSame(['Credit Waived', 'credit'], [$rows['credit_waived']['entry_label'], $rows['credit_waived']['direction']]);
    }

    public function test_unconfirmed_and_soft_deleted_wallet_rows_are_excluded(): void
    {
        $this->student->deposit(10000);
        $this->student->deposit(5000);

        $walletId = $this->student->wallet->id;

        DB::table('transactions')->where('wallet_id', $walletId)->limit(1)->update(['confirmed' => false]);
        DB::table('transactions')->where('wallet_id', $walletId)->where('confirmed', true)
            ->limit(1)->update(['deleted_at' => now()]);

        $response = $this->asCashier()->getJson($this->ledgerUrl());

        $this->assertCount(0, $response->json('data'), 'Unconfirmed and soft-deleted wallet rows must never surface.');
    }

    public function test_a_student_with_no_wallet_returns_credit_rows_only(): void
    {
        $this->ledger->charge($this->student, 40.00, 'R-NOWALLET', $this->cashier);

        $this->assertFalse(
            $this->student->fresh()->wallet->exists,
            'This test is meaningless unless the wallet is still unpersisted. bavix hands back an '
            .'in-memory default wallet on access, so the query must key off its id being null.'
        );

        $response = $this->asCashier()->getJson($this->ledgerUrl());

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('credit_charged', $response->json('data.0.entry_type'));
    }

    public function test_the_credit_filter_returns_only_credit_entry_types(): void
    {
        $this->student->deposit(30000);
        $this->student->withdraw(5000);
        $this->ledger->charge($this->student, 20.00, 'R-C', $this->cashier);

        $types = collect($this->asCashier()->getJson($this->ledgerUrl('entry_type=credit'))->json('data'))
            ->pluck('entry_type')
            ->unique()
            ->all();

        $this->assertSame(['credit_charged'], $types);
    }

    public function test_the_topup_filter_excludes_purchases_and_credit(): void
    {
        $this->student->deposit(30000);
        $this->student->withdraw(5000);
        $this->ledger->charge($this->student, 20.00, 'R-T', $this->cashier);

        $types = collect($this->asCashier()->getJson($this->ledgerUrl('entry_type=topup'))->json('data'))
            ->pluck('entry_type')
            ->unique()
            ->all();

        $this->assertSame(['deposit'], $types);
    }

    public function test_the_all_filter_returns_every_row(): void
    {
        $this->student->deposit(30000);
        $this->student->withdraw(5000);
        $this->ledger->charge($this->student, 20.00, 'R-ALL', $this->cashier);

        $this->assertCount(3, $this->asCashier()->getJson($this->ledgerUrl('entry_type=all'))->json('data'));
        $this->assertCount(3, $this->asCashier()->getJson($this->ledgerUrl())->json('data'));
    }

    public function test_date_filters_restrict_the_range(): void
    {
        $this->student->deposit(10000);
        $this->stampWalletRows(now()->subDays(10)->toDateTimeString());

        $charge = $this->ledger->charge($this->student, 15.00, 'R-RECENT', $this->cashier);
        $this->stampCreditRow($charge->id, now()->subDay()->toDateTimeString());

        $from = now()->subDays(3)->toDateString();

        $rows = $this->asCashier()->getJson($this->ledgerUrl("from={$from}"))->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('credit_charged', $rows[0]['entry_type']);
    }

    public function test_it_returns_the_standard_pagination_envelope_and_paginates(): void
    {
        foreach (range(1, 5) as $i) {
            $this->travelTo(now()->subMinutes($i), fn () => $this->ledger->charge($this->student, 5.00, "R-{$i}", $this->cashier));
        }

        $response = $this->asCashier()->getJson($this->ledgerUrl('per_page=2'));

        $response->assertOk()->assertJsonStructure([
            'data' => [['id', 'date', 'entry_type', 'entry_label', 'direction', 'amount', 'payment_method', 'reference_number', 'note', 'performed_by']],
            'meta' => ['current_page', 'last_page', 'total'],
        ]);

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(5, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));

        $page2 = $this->asCashier()->getJson($this->ledgerUrl('per_page=2&page=2'));
        $this->assertCount(2, $page2->json('data'));
        $this->assertNotSame($response->json('data.0.id'), $page2->json('data.0.id'));
    }

    public function test_row_ids_are_namespaced_by_source(): void
    {
        $this->student->deposit(10000);
        $this->ledger->charge($this->student, 10.00, 'R-ID', $this->cashier);

        $ids = collect($this->asCashier()->getJson($this->ledgerUrl())->json('data'))->pluck('id');

        $this->assertTrue($ids->contains(fn (string $id) => str_starts_with($id, 'credit-')));
        $this->assertTrue($ids->contains(fn (string $id) => str_starts_with($id, 'wallet-')));
    }

    public function test_credit_rows_expose_their_payment_method_and_reference(): void
    {
        $this->ledger->charge($this->student, 100.00, 'R-PM', $this->cashier);
        $this->ledger->settleWithPayment($this->student, 100.00, CreditSettlementMethod::Gcash, 'GC7X92A', 'Mother paid', $this->cashier);

        $row = collect($this->asCashier()->getJson($this->ledgerUrl('entry_type=credit'))->json('data'))
            ->firstWhere('entry_type', 'credit_settled');

        $this->assertSame('gcash', $row['payment_method']);
        $this->assertSame('GC7X92A', $row['reference_number']);
        $this->assertSame('Mother paid', $row['note']);
    }

    public function test_performer_names_are_resolved_for_both_sources(): void
    {
        // Cashiers cannot top up — that route is admin|manager|supervisor.
        $manager = User::factory()->create(['first_name' => 'Maris', 'last_name' => 'Cayao']);
        $manager->assignRole('manager');
        $manager->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Sanctum::actingAs($manager, ['staff']);
        $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->postJson("/api/v1/students/{$this->student->id}/wallet/top-up", [
                'amount' => 100.00,
                'payment_method' => 'cash',
            ])->assertOk();

        $this->ledger->charge($this->student, 10.00, 'R-WHO', $this->cashier);

        $rows = collect($this->asCashier()->getJson($this->ledgerUrl())->json('data'));

        $this->assertSame('Maris Cayao', $rows->firstWhere('entry_type', 'credit_charged')['performed_by']);
        $this->assertSame('Maris Cayao', $rows->firstWhere('entry_type', 'deposit')['performed_by']);
    }

    public function test_performer_names_resolve_in_a_single_batched_query(): void
    {
        foreach (range(1, 6) as $i) {
            $this->ledger->charge($this->student, 5.00, "R-N{$i}", $this->cashier);
        }
        $this->student->deposit(10000);

        $this->asCashier();

        DB::enableQueryLog();
        $this->getJson($this->ledgerUrl('per_page=20'))->assertOk();
        $userQueries = collect(DB::getRawQueryLog())
            ->filter(fn (array $q) => str_contains($q['raw_query'], 'from "users"'))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $userQueries, 'Performer names must be batched, never one query per row.');
    }

    public function test_the_student_show_payload_no_longer_carries_wallet_transactions(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        Sanctum::actingAs($admin, ['staff']);

        $this->withHeaders(['X-Branch-Id' => $this->branch->id])
            ->getJson("/api/v1/students/{$this->student->id}")
            ->assertOk()
            ->assertJsonMissingPath('wallet_transactions');
    }

    public function test_an_invalid_entry_type_filter_is_rejected(): void
    {
        $this->asCashier()->getJson($this->ledgerUrl('entry_type=nonsense'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('entry_type');
    }

    public function test_a_to_date_before_the_from_date_is_rejected(): void
    {
        $this->asCashier()->getJson($this->ledgerUrl('from=2026-07-10&to=2026-07-01'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson($this->ledgerUrl())->assertStatus(401);
    }

    public function test_a_student_outside_the_active_branch_is_not_reachable(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true]);
        $otherStudent = Student::factory()->create(['branch_id' => $otherBranch->id]);

        CreditTransaction::factory()->charged()->create([
            'student_id' => $otherStudent->id,
            'branch_id' => $otherBranch->id,
            'performed_by' => $this->cashier->id,
        ]);

        $this->asCashier()
            ->getJson("/api/v1/students/{$otherStudent->id}/ledger")
            ->assertStatus(404);
    }
}
