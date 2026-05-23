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
}
