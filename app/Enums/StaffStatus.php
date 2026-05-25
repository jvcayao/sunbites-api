<?php

namespace App\Enums;

enum StaffStatus: string
{
    case Working = 'Working';
    case Off = 'Off';
    case OnLeave = 'OnLeave';
    case Emergency = 'Emergency';
    case OnBreak = 'OnBreak';
}
