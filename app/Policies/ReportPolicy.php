<?php

namespace App\Policies;

use App\Models\User;

class ReportPolicy
{
    /**
     * Admins, managers, and supervisors may view reports.
     */
    public function view(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'supervisor']);
    }

    /**
     * Only admins and managers may export reports.
     * Supervisors are explicitly excluded.
     */
    public function export(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }
}
