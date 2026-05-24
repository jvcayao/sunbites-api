<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function view(User $user, Student $student): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'supervisor']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'supervisor']);
    }

    public function update(User $user, Student $student): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'supervisor']);
    }

    public function delete(User $user, Student $student): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'supervisor']);
    }

    public function topUp(User $user, Student $student): bool
    {
        return $user->hasAnyRole(['admin', 'manager', 'supervisor']);
    }

    public function settleCredit(User $user, Student $student): bool
    {
        return $user->hasAnyRole(['admin', 'manager']);
    }
}
