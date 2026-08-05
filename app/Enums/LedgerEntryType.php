<?php

namespace App\Enums;

/**
 * A single row in the unified student ledger, spanning both the wallet
 * transaction log and the credit transaction ledger.
 */
enum LedgerEntryType: string
{
    case Deposit = 'deposit';
    case Withdraw = 'withdraw';
    case TopupVoided = 'topup_voided';
    case CreditCharged = 'credit_charged';
    case CreditSettled = 'credit_settled';
    case CreditWaived = 'credit_waived';
    case CreditVoided = 'credit_voided';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Top-up',
            self::Withdraw => 'Purchase',
            self::TopupVoided => 'Top-up Voided',
            self::CreditCharged => 'Credit Charged',
            self::CreditSettled => 'Credit Paid',
            self::CreditWaived => 'Credit Waived',
            self::CreditVoided => 'Credit Reversed',
        };
    }

    /**
     * Whether the entry increases or decreases what the student effectively holds.
     *
     * Returned so the frontend renders sign and colour without inspecting the type.
     */
    public function direction(): string
    {
        return match ($this) {
            self::Withdraw, self::CreditCharged, self::TopupVoided => 'debit',
            self::Deposit, self::CreditSettled, self::CreditWaived, self::CreditVoided => 'credit',
        };
    }

    public function isCreditEntry(): bool
    {
        return match ($this) {
            self::CreditCharged, self::CreditSettled, self::CreditWaived, self::CreditVoided => true,
            self::Deposit, self::Withdraw, self::TopupVoided => false,
        };
    }

    /**
     * Entry types matched by each ledger filter exposed to both apps.
     *
     * @return array<int, self>
     */
    public static function forFilter(string $filter): array
    {
        return match ($filter) {
            'topup' => [self::Deposit, self::TopupVoided],
            'purchase' => [self::Withdraw],
            'credit' => [self::CreditCharged, self::CreditSettled, self::CreditWaived, self::CreditVoided],
            default => self::cases(),
        };
    }
}
