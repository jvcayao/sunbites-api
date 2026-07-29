<?php

namespace Tests\Feature\Reports;

use App\Enums\CreditSettlementMethod;
use App\Models\Branch;
use App\Models\Student;
use App\Models\User;
use App\Services\CreditLedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reporting for spec 14: payment-method breakdown on the credit report, the credit KPIs on
 * the wallet report, and the credit-collections block on the daily summary.
 */
class CreditSettlementReportingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Branch $branch;

    private User $admin;

    private CreditLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->branch = Branch::factory()->create(['is_active' => true, 'slug' => 'rpt']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->branches()->attach($this->branch->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->ledger = app(CreditLedgerService::class);
    }

    private function asAdmin(): static
    {
        Sanctum::actingAs($this->admin, ['staff']);

        return $this->withHeaders(['X-Branch-Id' => $this->branch->id]);
    }

    private function studentOwing(float $credit, float $walletPesos = 0, ?Branch $branch = null): Student
    {
        $student = Student::factory()->create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'credit_balance' => $credit,
        ]);

        if ($walletPesos > 0) {
            $student->deposit((int) round($walletPesos * 100));
        }

        return $student->refresh();
    }

    // ---------------------------------------------------------------- credit report

    public function test_the_credit_report_totals_waived_separately_from_settled(): void
    {
        $student = $this->studentOwing(0);
        $this->ledger->charge($student, 200.00, 'R-1', $this->admin);
        $this->ledger->settleWithPayment($student, 60.00, CreditSettlementMethod::Cash, null, null, $this->admin);
        $this->ledger->waive($student, 40.00, 'Partial write-off for testing.', $this->admin);

        $summary = $this->asAdmin()->getJson('/api/v1/reports/credits')->json('summary');

        $this->assertEquals(200.0, $summary['total_charged']);
        $this->assertEquals(60.0, $summary['total_settled']);
        $this->assertEquals(40.0, $summary['total_waived']);
        $this->assertEquals(0.0, $summary['total_voided']);
    }

    public function test_net_outstanding_subtracts_waived_amounts(): void
    {
        $student = $this->studentOwing(0);
        $this->ledger->charge($student, 200.00, 'R-2', $this->admin);
        $this->ledger->settleWithPayment($student, 60.00, CreditSettlementMethod::Cash, null, null, $this->admin);
        $this->ledger->waive($student, 40.00, 'Partial write-off for testing.', $this->admin);

        $summary = $this->asAdmin()->getJson('/api/v1/reports/credits')->json('summary');

        $this->assertEquals(100.0, $summary['net_outstanding']);
        $this->assertEquals(
            (float) $student->fresh()->credit_balance,
            $summary['net_outstanding'],
            'The report total must agree with the student ledger balance.'
        );
    }

    public function test_the_credit_report_breaks_settlements_down_by_payment_method(): void
    {
        $student = $this->studentOwing(0, 500.00);
        $this->ledger->charge($student, 400.00, 'R-3', $this->admin);

        $this->ledger->settleWithPayment($student, 100.00, CreditSettlementMethod::Cash, null, null, $this->admin);
        $this->ledger->settleWithPayment($student, 50.00, CreditSettlementMethod::Gcash, 'GC1', null, $this->admin);
        $this->ledger->settleWithPayment($student, 25.00, CreditSettlementMethod::BankTransfer, 'BDO1', null, $this->admin);
        $this->ledger->settleFromWallet($student, 75.00, null, $this->admin);

        $summary = $this->asAdmin()->getJson('/api/v1/reports/credits')->json('summary');

        $this->assertEquals(100.0, $summary['settled_by_method']['cash']);
        $this->assertEquals(50.0, $summary['settled_by_method']['gcash']);
        $this->assertEquals(25.0, $summary['settled_by_method']['bank_transfer']);
        $this->assertEquals(75.0, $summary['settled_by_method']['wallet']);

        $this->assertEquals(
            175.0,
            $summary['settled_bringing_in_cash'],
            'Wallet settlements bring in no money and must be excluded from the cash figure.'
        );
    }

    public function test_the_payment_method_filter_narrows_the_rows(): void
    {
        $student = $this->studentOwing(0);
        $this->ledger->charge($student, 200.00, 'R-4', $this->admin);
        $this->ledger->settleWithPayment($student, 60.00, CreditSettlementMethod::Cash, null, null, $this->admin);
        $this->ledger->settleWithPayment($student, 40.00, CreditSettlementMethod::Gcash, 'GC9', null, $this->admin);

        $rows = $this->asAdmin()->getJson('/api/v1/reports/credits?payment_method=gcash')->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('gcash', $rows[0]['payment_method']);
        $this->assertSame('GC9', $rows[0]['reference_number']);
    }

    public function test_the_waived_type_filter_is_accepted(): void
    {
        $student = $this->studentOwing(0);
        $this->ledger->charge($student, 200.00, 'R-5', $this->admin);
        $this->ledger->waive($student, 200.00, 'Written off for testing purposes.', $this->admin);

        $rows = $this->asAdmin()->getJson('/api/v1/reports/credits?type=waived')->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('waived', $rows[0]['type']);
        $this->assertNull($rows[0]['payment_method']);
    }

    public function test_rows_expose_the_performer_as_a_plain_string(): void
    {
        $student = $this->studentOwing(0);
        $this->ledger->charge($student, 25.00, 'R-6', $this->admin);

        $row = $this->asAdmin()->getJson('/api/v1/reports/credits')->json('data.0');

        $this->assertIsString($row['performed_by']);
        $this->assertSame($this->admin->full_name, $row['performed_by']);
    }

    public function test_another_branch_settlement_is_excluded_from_this_branch_report(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true, 'slug' => 'oth']);
        $foreign = $this->studentOwing(0, 0, $otherBranch);

        $this->ledger->charge($foreign, 90.00, 'R-FOREIGN', $this->admin);

        $local = $this->studentOwing(0);
        $this->ledger->charge($local, 10.00, 'R-LOCAL', $this->admin);

        $response = $this->asAdmin()->getJson('/api/v1/reports/credits');

        $this->assertCount(1, $response->json('data'));
        $this->assertEquals(10.0, $response->json('summary.total_charged'));
    }

    // ---------------------------------------------------------------- wallet report

    public function test_the_wallet_report_reports_outstanding_credit_branch_wide(): void
    {
        $this->studentOwing(150.00, 100.00);
        $this->studentOwing(50.00, 100.00);
        $this->studentOwing(0, 100.00);

        $summary = $this->asAdmin()->getJson('/api/v1/reports/wallet')->json('summary');

        $this->assertEquals(200.0, $summary['total_outstanding_credit']);
        $this->assertSame(2, $summary['students_with_credit']);
    }

    public function test_the_wallet_report_credit_total_agrees_with_the_credit_report(): void
    {
        $student = $this->studentOwing(0, 100.00);
        $this->ledger->charge($student, 120.00, 'R-AGREE', $this->admin);

        $walletTotal = $this->asAdmin()->getJson('/api/v1/reports/wallet')->json('summary.total_outstanding_credit');
        $creditNet = $this->asAdmin()->getJson('/api/v1/reports/credits')->json('summary.net_outstanding');

        $this->assertEquals(
            $creditNet,
            $walletTotal,
            'Two screens must never give different answers to "how much is owed".'
        );
    }

    public function test_the_has_credit_filter_excludes_students_with_none(): void
    {
        $owing = $this->studentOwing(150.00, 100.00);
        $this->studentOwing(0, 100.00);

        $rows = $this->asAdmin()->getJson('/api/v1/reports/wallet?has_credit=1')->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($owing->id, $rows[0]['id']);
        $this->assertEquals(150.0, $rows[0]['outstanding_credit']);
    }

    public function test_the_wallet_report_can_sort_by_outstanding_credit(): void
    {
        $small = $this->studentOwing(20.00, 100.00);
        $large = $this->studentOwing(300.00, 100.00);

        $rows = $this->asAdmin()->getJson('/api/v1/reports/wallet?sort=credit')->json('data');

        $ids = collect($rows)->pluck('id')->all();

        $this->assertSame($large->id, $ids[0], 'Sorting by credit must put the biggest debtor first.');
        $this->assertContains($small->id, $ids);
    }

    public function test_the_wallet_history_endpoint_serves_a_credit_panel(): void
    {
        $student = $this->studentOwing(0, 100.00);
        $this->ledger->charge($student, 40.00, 'R-PANEL', $this->admin);
        $this->ledger->settleWithPayment($student, 40.00, CreditSettlementMethod::Cash, null, null, $this->admin);

        $response = $this->asAdmin()->getJson("/api/v1/reports/wallet/{$student->id}/history?type=credit");

        $response->assertOk()->assertJsonStructure([
            'data' => [['id', 'date', 'type', 'type_label', 'amount', 'payment_method', 'description', 'added_by']],
            'meta' => ['current_page', 'last_page', 'total'],
        ]);

        $this->assertSame(2, $response->json('meta.total'));
    }

    // ---------------------------------------------------------------- daily summary

    public function test_the_daily_summary_reports_credit_collections_by_method(): void
    {
        $student = $this->studentOwing(0, 500.00);
        $this->ledger->charge($student, 400.00, 'R-DS', $this->admin);

        $this->ledger->settleWithPayment($student, 100.00, CreditSettlementMethod::Cash, null, null, $this->admin);
        $this->ledger->settleWithPayment($student, 50.00, CreditSettlementMethod::Gcash, 'GC2', null, $this->admin);
        $this->ledger->settleWithPayment($student, 25.00, CreditSettlementMethod::BankTransfer, 'BDO2', null, $this->admin);
        $this->ledger->settleFromWallet($student, 75.00, null, $this->admin);

        $collections = $this->asAdmin()->getJson('/api/v1/reports/daily-summary')->json('credit_collections');

        $this->assertEquals(250.0, $collections['total']);
        $this->assertSame(4, $collections['count']);
        $this->assertEquals(100.0, $collections['cash']);
        $this->assertEquals(50.0, $collections['gcash']);
        $this->assertEquals(25.0, $collections['bank_transfer']);
        $this->assertEquals(75.0, $collections['from_wallet']);
        $this->assertEquals(175.0, $collections['expected_in_drawer'], 'Wallet settlements add no cash to the drawer.');
    }

    public function test_a_settlement_does_not_change_revenue_or_the_payment_breakdown(): void
    {
        $student = $this->studentOwing(150.00);

        $before = $this->asAdmin()->getJson('/api/v1/reports/daily-summary')->json();

        $this->ledger->settleWithPayment($student, 150.00, CreditSettlementMethod::Cash, null, null, $this->admin);

        $after = $this->asAdmin()->getJson('/api/v1/reports/daily-summary')->json();

        $this->assertSame(
            $before['total_revenue'],
            $after['total_revenue'],
            'Settlement revenue was booked on the original order date; counting it again double-counts.'
        );
        $this->assertSame($before['payment_breakdown'], $after['payment_breakdown']);
        $this->assertSame($before['total_orders'], $after['total_orders']);
    }

    public function test_a_day_with_no_settlements_reports_zero(): void
    {
        $collections = $this->asAdmin()->getJson('/api/v1/reports/daily-summary')->json('credit_collections');

        $this->assertEquals(0.0, $collections['total']);
        $this->assertSame(0, $collections['count']);
        $this->assertEquals(0.0, $collections['expected_in_drawer']);
    }

    public function test_credit_collections_only_count_the_requested_day(): void
    {
        $student = $this->studentOwing(150.00);
        $entry = $this->ledger->settleWithPayment($student, 150.00, CreditSettlementMethod::Cash, null, null, $this->admin);

        DB::table('credit_transactions')
            ->where('id', $entry->id)
            ->update(['created_at' => now()->subDays(5)->toDateTimeString()]);

        $today = $this->asAdmin()->getJson('/api/v1/reports/daily-summary')->json('credit_collections');
        $this->assertEquals(0.0, $today['total']);

        $thatDay = $this->asAdmin()
            ->getJson('/api/v1/reports/daily-summary?date='.now()->subDays(5)->toDateString())
            ->json('credit_collections');

        $this->assertEquals(150.0, $thatDay['total']);
    }

    public function test_another_branch_settlement_is_excluded_from_the_daily_summary(): void
    {
        $otherBranch = Branch::factory()->create(['is_active' => true, 'slug' => 'ob2']);
        $foreign = $this->studentOwing(200.00, 0, $otherBranch);

        $this->ledger->settleWithPayment($foreign, 200.00, CreditSettlementMethod::Cash, null, null, $this->admin);

        $collections = $this->asAdmin()->getJson('/api/v1/reports/daily-summary')->json('credit_collections');

        $this->assertEquals(0.0, $collections['total']);
    }
}
