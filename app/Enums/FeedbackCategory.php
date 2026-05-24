<?php

namespace App\Enums;

enum FeedbackCategory: string
{
    case FoodQuality = 'FoodQuality';
    case Service = 'Service';
    case PortionSize = 'PortionSize';
    case Cleanliness = 'Cleanliness';
    case General = 'General';

    public function label(): string
    {
        return match ($this) {
            self::FoodQuality => 'Food Quality',
            self::Service => 'Service',
            self::PortionSize => 'Portion Size',
            self::Cleanliness => 'Cleanliness',
            self::General => 'General',
        };
    }
}
