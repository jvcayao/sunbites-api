<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Completed = 'completed';
    case Voided = 'voided';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
