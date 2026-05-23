<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    case Charged = 'charged';
    case Settled = 'settled';
    case Voided = 'voided';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
