<?php

namespace App\Enums;

enum StudentType: string
{
    case Subscription = 'subscription';
    case NonSubscription = 'non_subscription';

    public function label(): string
    {
        return match ($this) {
            self::Subscription => 'Subscription',
            self::NonSubscription => 'Non-Subscription',
        };
    }
}
