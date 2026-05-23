<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    case Enrolled = 'enrolled';
    case Paused = 'paused';
    case Unenrolled = 'unenrolled';
    case Banned = 'banned';
    case Graduated = 'graduated';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function requiresReason(): bool
    {
        return match ($this) {
            self::Banned, self::Unenrolled => true,
            default => false,
        };
    }
}
