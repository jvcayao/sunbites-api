<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Gcash = 'gcash';
    case Wallet = 'wallet';
    case Subscription = 'subscription';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Gcash => 'GCash',
            self::Wallet => 'Student Wallet',
            self::Subscription => 'Subscription',
        };
    }
}
