<?php

namespace Tests\Feature\Kitchen;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WalletTopupVoidMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_wallet_topup_voids_table_has_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('wallet_topup_voids', [
            'id',
            'student_id',
            'branch_id',
            'wallet_transaction_id',
            'refund_wallet_transaction_id',
            'credit_transaction_id',
            'original_amount',
            'voided_amount',
            'shortfall_amount',
            'void_reason',
            'voided_by',
            'created_at',
        ]));

        $this->assertFalse(
            Schema::hasColumn('wallet_topup_voids', 'updated_at'),
            'wallet_topup_voids is an append-only ledger and must not have an updated_at column.'
        );
    }

    public function test_migrations_down_method_drops_the_table_cleanly(): void
    {
        $migration = require base_path('database/migrations/2026_08_03_205421_create_wallet_topup_voids_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('wallet_topup_voids'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('wallet_topup_voids'));
    }
}
