<?php

namespace App\Enums;

enum CreditSettlementMethod: string
{
    case Cash = 'cash';
    case Gcash = 'gcash';
    case BankTransfer = 'bank_transfer';
    case Wallet = 'wallet';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Gcash => 'GCash',
            self::BankTransfer => 'Bank Transfer',
            self::Wallet => 'From Wallet',
        };
    }

    /**
     * Whether settling through this method brings physical money into the branch.
     *
     * Wallet settlements move an existing prepaid balance against the debt, so they
     * must never be counted toward expected cash drawer totals.
     */
    public function bringsInCash(): bool
    {
        return $this !== self::Wallet;
    }

    public function requiresReferenceNumber(): bool
    {
        return in_array($this, [self::Gcash, self::BankTransfer], true);
    }
}
