<?php

namespace App\Enums;

enum SchoolMonth: string
{
    case June = 'june';
    case July = 'july';
    case August = 'august';
    case September = 'september';
    case October = 'october';
    case November = 'november';
    case December = 'december';
    case January = 'january';
    case February = 'february';
    case March = 'march';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function toMonthNumber(): int
    {
        return match ($this) {
            self::June => 6,
            self::July => 7,
            self::August => 8,
            self::September => 9,
            self::October => 10,
            self::November => 11,
            self::December => 12,
            self::January => 1,
            self::February => 2,
            self::March => 3,
        };
    }

    public static function fromMonthNumber(int $month): ?self
    {
        return match ($month) {
            1 => self::January,
            2 => self::February,
            3 => self::March,
            6 => self::June,
            7 => self::July,
            8 => self::August,
            9 => self::September,
            10 => self::October,
            11 => self::November,
            12 => self::December,
            default => null,
        };
    }
}
