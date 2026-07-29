<?php

namespace Tests\Unit;

use App\Enums\CreditSettlementMethod;
use App\Enums\CreditTransactionType;
use App\Enums\LedgerEntryType;
use PHPUnit\Framework\TestCase;

class CreditEnumsTest extends TestCase
{
    public function test_credit_transaction_type_resolves_waived_from_string(): void
    {
        $type = CreditTransactionType::from('waived');

        $this->assertSame(CreditTransactionType::Waived, $type);
        $this->assertSame('Waived', $type->label());
    }

    public function test_credit_transaction_type_exposes_all_four_ledger_events(): void
    {
        $values = array_map(fn (CreditTransactionType $case) => $case->value, CreditTransactionType::cases());

        $this->assertSame(['charged', 'settled', 'waived', 'voided'], $values);
    }

    public function test_every_settlement_method_has_a_label(): void
    {
        foreach (CreditSettlementMethod::cases() as $method) {
            $this->assertNotSame('', $method->label(), "{$method->value} is missing a label.");
        }
    }

    public function test_only_wallet_settlement_brings_in_no_cash(): void
    {
        $this->assertTrue(CreditSettlementMethod::Cash->bringsInCash());
        $this->assertTrue(CreditSettlementMethod::Gcash->bringsInCash());
        $this->assertTrue(CreditSettlementMethod::BankTransfer->bringsInCash());
        $this->assertFalse(CreditSettlementMethod::Wallet->bringsInCash());
    }

    public function test_only_gcash_and_bank_transfer_require_a_reference_number(): void
    {
        $this->assertTrue(CreditSettlementMethod::Gcash->requiresReferenceNumber());
        $this->assertTrue(CreditSettlementMethod::BankTransfer->requiresReferenceNumber());
        $this->assertFalse(CreditSettlementMethod::Cash->requiresReferenceNumber());
        $this->assertFalse(CreditSettlementMethod::Wallet->requiresReferenceNumber());
    }

    public function test_ledger_entry_types_expose_expected_labels(): void
    {
        $this->assertSame('Top-up', LedgerEntryType::Deposit->label());
        $this->assertSame('Purchase', LedgerEntryType::Withdraw->label());
        $this->assertSame('Credit Charged', LedgerEntryType::CreditCharged->label());
        $this->assertSame('Credit Paid', LedgerEntryType::CreditSettled->label());
        $this->assertSame('Credit Waived', LedgerEntryType::CreditWaived->label());
        $this->assertSame('Credit Reversed', LedgerEntryType::CreditVoided->label());
    }

    public function test_only_withdrawals_and_credit_charges_are_debits(): void
    {
        $this->assertSame('debit', LedgerEntryType::Withdraw->direction());
        $this->assertSame('debit', LedgerEntryType::CreditCharged->direction());

        $this->assertSame('credit', LedgerEntryType::Deposit->direction());
        $this->assertSame('credit', LedgerEntryType::CreditSettled->direction());
        $this->assertSame('credit', LedgerEntryType::CreditWaived->direction());
        $this->assertSame('credit', LedgerEntryType::CreditVoided->direction());
    }

    public function test_every_ledger_entry_type_has_a_valid_direction(): void
    {
        foreach (LedgerEntryType::cases() as $type) {
            $this->assertContains($type->direction(), ['debit', 'credit'], "{$type->value} has an invalid direction.");
        }
    }

    public function test_credit_entries_are_distinguished_from_wallet_entries(): void
    {
        $this->assertTrue(LedgerEntryType::CreditCharged->isCreditEntry());
        $this->assertTrue(LedgerEntryType::CreditSettled->isCreditEntry());
        $this->assertTrue(LedgerEntryType::CreditWaived->isCreditEntry());
        $this->assertTrue(LedgerEntryType::CreditVoided->isCreditEntry());

        $this->assertFalse(LedgerEntryType::Deposit->isCreditEntry());
        $this->assertFalse(LedgerEntryType::Withdraw->isCreditEntry());
    }

    public function test_topup_filter_matches_only_deposits(): void
    {
        $this->assertSame([LedgerEntryType::Deposit], LedgerEntryType::forFilter('topup'));
    }

    public function test_purchase_filter_matches_only_withdrawals(): void
    {
        $this->assertSame([LedgerEntryType::Withdraw], LedgerEntryType::forFilter('purchase'));
    }

    public function test_credit_filter_matches_all_four_credit_entry_types(): void
    {
        $this->assertSame([
            LedgerEntryType::CreditCharged,
            LedgerEntryType::CreditSettled,
            LedgerEntryType::CreditWaived,
            LedgerEntryType::CreditVoided,
        ], LedgerEntryType::forFilter('credit'));
    }

    public function test_unknown_filter_falls_back_to_every_entry_type(): void
    {
        $this->assertSame(LedgerEntryType::cases(), LedgerEntryType::forFilter('all'));
        $this->assertSame(LedgerEntryType::cases(), LedgerEntryType::forFilter('nonsense'));
    }
}
